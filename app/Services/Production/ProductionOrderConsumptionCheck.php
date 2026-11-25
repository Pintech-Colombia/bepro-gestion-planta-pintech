<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderConsumptionReason;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionOrderLineAdjustment;
use App\Models\ProductionOrderProductLine;
use App\Models\RawMaterial;
use App\Services\DecimalCalculator;
use App\Support\NumberFormat;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * La tolerancia de consumo de una OP, en un solo lugar: enviar a revisión, completar, el detalle de la OP y la alerta de
 * sobreconsumo leen de aquí si una materia prima se pasó de lo planeado. La pantalla no repite la cuenta.
 *
 * Por materia prima, en su unidad y dentro de la misma OP: consumido = lo real de todas sus líneas (pasos) + sus ajustes
 * de línea, contra planeado = lo planeado de todas sus líneas. Se pasa si consumido > planeado × (1 + % / 100); justo en
 * el límite no se pasa. Se compara por materia prima y no por línea: 30 kg en un paso y 30 en otro, gastar 60 en el
 * primero y 0 en el segundo no es pasarse. Una materia prima sin nada planeado (solo por ajuste) no se evalúa: el ajuste
 * ya trae su motivo. Un rendimiento menor al planeado no es un error y no se mira aquí.
 *
 * El motivo se pide solo si lo real **sin ajustes** pasa el límite: el exceso que viene de ajustes de línea ya tiene el
 * suyo. La alerta cuenta todo (`exceeds`), porque el costo es real.
 *
 * El % de cada materia prima es el de su familia (`raw_material_families.consumption_tolerance_percentage`) o, si la
 * familia no tiene, el general (`config/production.php`). Al completar, cada línea guarda el suyo: la completada sigue
 * mostrando lo que se evaluó aunque cambie el de la familia (las completadas antes usan el que guardó la orden).
 *
 * Lo mismo para el producto terminado que la OP lleva como ingrediente (PR E2a de PT consumible), aparte y en kilos: lo
 * gastado de todas sus líneas contra lo planeado de todas sus líneas, siempre con el % general (no tiene familia). Un producto sin nada planeado (solo
 * líneas agregadas en la orden) no se evalúa. No tiene ajustes de línea: si se pasa, pide motivo. Su motivo va en
 * `product_overconsumption_reasons.<id del producto>`, una llave aparte para que un id de MP y uno de producto no choquen.
 *
 * @phpstan-type ConsumptionRow array{
 *   raw_material_id: int,
 *   code: string|null,
 *   unit: string|null,
 *   planned: string,
 *   consumed: string,
 *   consumed_without_adjustments: string,
 *   limit: string,
 *   tolerance_percentage: string,
 *   excess_percentage: string,
 *   exceeds: bool,
 *   needs_reason: bool
 * }
 * @phpstan-type ProductConsumptionRow array{
 *   product_id: int,
 *   name: string|null,
 *   unit: string,
 *   planned: string,
 *   consumed: string,
 *   limit: string,
 *   tolerance_percentage: string,
 *   excess_percentage: string,
 *   exceeds: bool,
 *   needs_reason: bool
 * }
 */
class ProductionOrderConsumptionCheck
{
    public const REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly DecimalCalculator $calculator,
        private readonly ProductLineCosts $productLineCosts,
    ) {}

    /** El % general vigente (`config/production.php`): el de las familias sin el suyo y el del producto terminado. */
    public function currentTolerancePercentage(): string
    {
        return $this->calculator->round((string) config('production.consumption_tolerance_percentage'), 2);
    }

    /**
     * El % vigente de cada materia prima: el de la familia de su categoría o, si no tiene, el general. Una consulta para
     * todas. También vale el de una familia desactivada: desactivarla solo impide elegirla en categorías nuevas, y las
     * que ya la tienen siguen agrupadas en ella.
     *
     * @param  list<int>  $rawMaterialIds
     * @return array<int, string> id de la materia prima => %
     */
    public function tolerancesByMaterial(array $rawMaterialIds): array
    {
        if ($rawMaterialIds === []) {
            return [];
        }

        $general = $this->currentTolerancePercentage();
        $byFamily = RawMaterial::query()
            ->leftJoin('raw_material_categories', 'raw_material_categories.id', '=', 'raw_materials.category_id')
            ->leftJoin('raw_material_families', 'raw_material_families.id', '=', 'raw_material_categories.family_id')
            ->whereIn('raw_materials.id', $rawMaterialIds)
            ->pluck('raw_material_families.consumption_tolerance_percentage', 'raw_materials.id');

        $tolerances = [];
        foreach ($rawMaterialIds as $id) {
            $family = $byFamily->get($id);
            $tolerances[(int) $id] = $family !== null ? $this->calculator->round((string) $family, 2) : $general;
        }

        return $tolerances;
    }

    /**
     * Cada materia prima planeada de la orden, con lo consumido y si se pasa.
     *
     * @param  array<int, string>|null  $actualByLine  id de la línea => lo real, ya en la unidad de la materia prima. Sin
     *                                                 él, lo guardado en cada línea (vacío cuenta como 0).
     * @param  string|null  $orderTolerance  al mostrar una completada, el % que guardó la orden: cada línea usa el suyo y,
     *                                       si no tiene (completada antes de la tolerancia por familia), este. Sin él, el
     *                                       vigente de cada materia prima.
     * @param  array<int, string>|null  $tolerances  el vigente de cada materia prima, si quien llama ya lo tiene
     *                                               (`tolerancesByMaterial`): completar lo calcula una vez para evaluar y
     *                                               para guardarlo en las líneas, así los dos no pueden diferir.
     * @return list<ConsumptionRow>
     */
    public function evaluate(ProductionOrder $order, ?array $actualByLine = null, ?string $orderTolerance = null, ?array $tolerances = null): array
    {
        $order->loadMissing(['details.rawMaterial.unitOfMeasure', 'lineAdjustments']);

        $adjustedByMaterial = [];
        foreach ($order->lineAdjustments as $adjustment) {
            /** @var ProductionOrderLineAdjustment $adjustment */
            $id = (int) $adjustment->raw_material_id;
            $adjustedByMaterial[$id] = $this->calculator->add($adjustedByMaterial[$id] ?? '0', (string) $adjustment->quantity, 4);
        }

        /** @var array<int, array{code: string|null, unit: string|null, planned: string, actual: string}> $materials */
        $materials = [];
        /** @var array<int, string|null> $stored el % que guardó la línea al completar */
        $stored = [];
        // Lo guardado solo cuenta al mostrar una completada (`forOrder` pasa el % de la orden); si no, se mide con el vigente.
        $showingCompleted = $orderTolerance !== null;
        foreach ($order->details as $detail) {
            /** @var ProductionOrderDetail $detail */
            $id = (int) $detail->raw_material_id;
            $stored[$id] ??= $showingCompleted && $detail->consumption_tolerance_percentage !== null
                ? (string) $detail->consumption_tolerance_percentage
                : null;
            $actual = $actualByLine !== null && array_key_exists((int) $detail->id, $actualByLine)
                ? (string) $actualByLine[(int) $detail->id]
                : (string) ($detail->actual_quantity ?? '0');

            $materials[$id] ??= [
                'code' => $detail->rawMaterial?->code,
                'unit' => $detail->rawMaterial?->unitOfMeasure?->symbol,
                'planned' => '0',
                'actual' => '0',
            ];
            $materials[$id]['planned'] = $this->calculator->add($materials[$id]['planned'], (string) $detail->planned_quantity, 4);
            $materials[$id]['actual'] = $this->calculator->add($materials[$id]['actual'], $actual, 4);
        }

        // El guardado en la línea; si no, el de la orden; si no, el vigente (una consulta para las que lo necesitan).
        $pending = array_keys(array_filter($stored, fn (?string $tolerance): bool => $tolerance === null));
        $current = $orderTolerance === null ? ($tolerances ?? $this->tolerancesByMaterial($pending)) : [];

        $rows = [];
        foreach ($materials as $id => $material) {
            if (! $this->calculator->isPositive($material['planned'])) {
                continue;
            }

            $tolerance = $stored[$id] ?? $orderTolerance ?? $current[$id];
            $consumed = $this->calculator->add($material['actual'], $adjustedByMaterial[$id] ?? '0', 4);

            $rows[] = [
                'raw_material_id' => $id,
                'code' => $material['code'],
                'unit' => $material['unit'],
                ...$this->measure($material['planned'], $consumed, $material['actual'], $tolerance),
                'consumed_without_adjustments' => $material['actual'],
            ];
        }

        return $rows;
    }

    /**
     * Cada producto planeado de la orden, en kilos, con lo gastado y si se pasa.
     *
     * @param  array<int, string>|null  $actualByLine  id de la línea de producto => lo gastado en kilos. Sin él, lo guardado
     *                                                 (`actual_kg`; vacío cuenta como 0).
     * @param  string|null  $tolerancePercentage  sin él, el general vigente (el producto no tiene familia)
     * @return list<ProductConsumptionRow>
     */
    public function evaluateProducts(ProductionOrder $order, ?array $actualByLine = null, ?string $tolerancePercentage = null): array
    {
        $order->loadMissing('productLines.product:id,name');

        $tolerance = $tolerancePercentage ?? $this->currentTolerancePercentage();

        /** @var array<int, array{name: string|null, planned: string, actual: string}> $products */
        $products = [];
        foreach ($order->productLines as $line) {
            /** @var ProductionOrderProductLine $line */
            $id = (int) $line->product_id;
            $actual = $actualByLine !== null && array_key_exists((int) $line->id, $actualByLine)
                ? (string) $actualByLine[(int) $line->id]
                : (string) ($line->actual_kg ?? '0');

            $products[$id] ??= ['name' => $line->product?->name, 'planned' => '0', 'actual' => '0'];
            $products[$id]['planned'] = $this->calculator->add($products[$id]['planned'], (string) ($line->planned_kg ?? '0'), 4);
            $products[$id]['actual'] = $this->calculator->add($products[$id]['actual'], $actual === '' ? '0' : $actual, 4);
        }

        $rows = [];
        foreach ($products as $id => $product) {
            if (! $this->calculator->isPositive($product['planned'])) {
                continue;
            }

            $rows[] = [
                'product_id' => $id,
                'name' => $product['name'],
                'unit' => 'kg',
                ...$this->measure($product['planned'], $product['actual'], $product['actual'], $tolerance),
            ];
        }

        return $rows;
    }

    /**
     * El límite, el exceso y si se pasa: una sola cuenta para materias primas y productos. `$consumedForReason` es lo que
     * pide motivo (en una MP, sin sus ajustes de línea).
     *
     * @return array{planned: string, consumed: string, limit: string, tolerance_percentage: string, excess_percentage: string, exceeds: bool, needs_reason: bool}
     */
    private function measure(string $planned, string $consumed, string $consumedForReason, string $tolerance): array
    {
        $factor = $this->calculator->add('1', $this->calculator->div($tolerance, '100', 8), 8);
        $limit = $this->calculator->mul($planned, $factor, 8);

        return [
            'planned' => $planned,
            'consumed' => $consumed,
            'limit' => $this->calculator->round($limit, 4),
            'tolerance_percentage' => $tolerance,
            'excess_percentage' => $this->calculator->round(
                $this->calculator->mul($this->calculator->div($this->calculator->sub($consumed, $planned, 8), $planned, 8), '100', 8),
                2,
            ),
            'exceeds' => $this->calculator->cmp($consumed, $limit, 8) > 0,
            'needs_reason' => $this->calculator->cmp($consumedForReason, $limit, 8) > 0,
        ];
    }

    /**
     * Lo que la orden muestra con lo guardado: las materias primas que se pasan, con su motivo. Completada, con el % con
     * que se evaluó al completar (una anterior a la tolerancia no lo tiene y no marca nada) y el costo del exceso; en
     * revisión, con el vigente. Pendiente, en curso o cancelada: nada (lo digitado aún no es lo entregado).
     *
     * @return list<ConsumptionRow&array{reason: string|null, extra_cost: string|null}>
     */
    public function forOrder(ProductionOrder $order): array
    {
        $tolerance = $this->shownTolerance($order);

        if ($tolerance === null) {
            return [];
        }

        // En revisión, el vigente de cada materia prima; completada, el que guardó cada línea (o la orden).
        $orderTolerance = $order->status === ProductionOrderStatus::Completed ? $tolerance : null;

        $order->loadMissing('consumptionReasons');
        $reasons = $order->consumptionReasons->whereNotNull('raw_material_id')->pluck('reason', 'raw_material_id');
        $isCompleted = $order->status === ProductionOrderStatus::Completed;

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'reason' => $reasons->get($row['raw_material_id']),
                'extra_cost' => $isCompleted ? $this->extraCost($order, $row) : null,
            ],
            array_filter($this->evaluate($order, null, $orderTolerance), fn (array $row): bool => $row['exceeds']),
        ));
    }

    /**
     * Lo mismo que `forOrder` para los productos: los que se pasan, con su motivo y, completada, el costo del exceso.
     *
     * @return list<ProductConsumptionRow&array{reason: string|null, extra_cost: string|null}>
     */
    public function productsForOrder(ProductionOrder $order): array
    {
        $tolerance = $this->shownTolerance($order);

        if ($tolerance === null) {
            return [];
        }

        $order->loadMissing('consumptionReasons');
        $reasons = $order->consumptionReasons->whereNotNull('product_id')->pluck('reason', 'product_id');
        $exceeding = array_filter($this->evaluateProducts($order, null, $tolerance), fn (array $row): bool => $row['exceeds']);
        // El costo de las líneas, una sola vez para todos los productos que se pasan (solo completada y si hay alguno).
        $lineCosts = $order->status === ProductionOrderStatus::Completed && $exceeding !== []
            ? $this->productLineCosts->forOrder($order, true)
            : null;

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'reason' => $reasons->get($row['product_id']),
                'extra_cost' => $lineCosts !== null ? $this->productExtraCost($order, $row, $lineCosts) : null,
            ],
            $exceeding,
        ));
    }

    /**
     * Enviar a revisión y completar: con lo real ya convertido, 422 si a una materia prima o a un producto que se pasa
     * le falta el motivo (`overconsumption_reasons.<id de la materia prima>`, `product_overconsumption_reasons.<id del
     * producto>`); si no, guarda los motivos. El de uno que ya no lo necesita se borra. Corre dentro de la transacción de
     * la acción, con la orden bloqueada.
     *
     * @param  array<int|string, array<string, mixed>>  $ingredients  las líneas enviadas, con `actual_quantity` ya en la
     *                                                                unidad de la materia prima (`ProductionOrderLineUnits`)
     * @param  array<int|string, mixed>  $reasons  id de la materia prima => motivo
     * @param  array<int|string, array<string, mixed>>  $productLines  las líneas de producto enviadas: id, actual_kg
     * @param  array<int|string, mixed>  $productReasons  id del producto => motivo
     * @param  array<int, string>|null  $tolerances  el % vigente de cada materia prima, si quien llama ya lo calculó
     *
     * @throws ValidationException si falta un motivo
     */
    public function recordReasons(ProductionOrder $order, array $ingredients, array $reasons, int $userId, array $productLines = [], array $productReasons = [], ?array $tolerances = null): void
    {
        $actualByLine = collect($ingredients)
            ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => (string) $row['actual_quantity']])
            ->all();
        $actualByProductLine = collect($productLines)
            ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => (string) ($row['actual_kg'] ?? '0')])
            ->all();
        $errors = [];

        $writtenMaterials = $this->typedReasons(
            array_filter($this->evaluate($order, $actualByLine, null, $tolerances), fn (array $row): bool => $row['needs_reason']),
            'raw_material_id',
            'overconsumption_reasons',
            $reasons,
            $errors,
        );
        $writtenProducts = $this->typedReasons(
            array_filter($this->evaluateProducts($order, $actualByProductLine), fn (array $row): bool => $row['needs_reason']),
            'product_id',
            'product_overconsumption_reasons',
            $productReasons,
            $errors,
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $stored = $order->consumptionReasons()->get();
        $this->saveReasons($order, $stored->whereNotNull('raw_material_id')->keyBy('raw_material_id'), 'raw_material_id', $writtenMaterials, $userId);
        $this->saveReasons($order, $stored->whereNotNull('product_id')->keyBy('product_id'), 'product_id', $writtenProducts, $userId);

        $order->unsetRelation('consumptionReasons');
    }

    /**
     * Los motivos escritos de lo que lo necesita, por id; un 422 por cada uno que falta.
     *
     * @param  array<int, array<string, mixed>>  $needing
     * @param  array<int|string, mixed>  $typed
     * @param  array<string, string>  $errors
     * @return array<int, string>
     */
    private function typedReasons(array $needing, string $idKey, string $field, array $typed, array &$errors): array
    {
        $written = [];

        foreach ($needing as $row) {
            $id = (int) $row[$idKey];
            $reason = trim((string) ($typed[$id] ?? ''));

            if ($reason === '') {
                $errors["{$field}.{$id}"] = $this->reasonRequiredMessage($row);

                continue;
            }

            $written[$id] = $reason;
        }

        return $written;
    }

    /**
     * Guarda los motivos escritos y borra los que ya no se necesitan, uno por uno para que la auditoría registre cada
     * cambio.
     *
     * @param  Collection<int|string, ProductionOrderConsumptionReason>  $existing
     * @param  array<int, string>  $written
     */
    private function saveReasons(ProductionOrder $order, Collection $existing, string $idKey, array $written, int $userId): void
    {
        foreach ($existing as $id => $stored) {
            if (! array_key_exists((int) $id, $written)) {
                $stored->delete();
            }
        }

        foreach ($written as $id => $reason) {
            $stored = $existing->get($id);

            if ($stored !== null) {
                $stored->update(['reason' => $reason]);

                continue;
            }

            $order->consumptionReasons()->create([
                $idKey => $id,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * El % general con que la orden muestra lo que se pasó (el del producto terminado, y el de las líneas de materia prima
     * de una completada antes de la tolerancia por familia): completada, el que se guardó al completar (una anterior a la
     * tolerancia no lo tiene y no marca nada); en revisión, el vigente; si no, ninguno.
     */
    private function shownTolerance(ProductionOrder $order): ?string
    {
        return match ($order->status) {
            ProductionOrderStatus::Completed => $order->consumption_tolerance_percentage !== null
                ? (string) $order->consumption_tolerance_percentage
                : null,
            ProductionOrderStatus::PendingReview => $this->currentTolerancePercentage(),
            default => null,
        };
    }

    /**
     * Lo que costó el exceso sobre lo planeado: (consumido − planeado) × el costo real por unidad de esa materia prima
     * en la orden (promedio ponderado de sus líneas). Solo con la orden completada, que es cuando las líneas tienen su
     * costo real; null si sus líneas no consumieron nada (todo vino por ajustes).
     *
     * @param  ConsumptionRow  $row
     */
    public function extraCost(ProductionOrder $order, array $row): ?string
    {
        $lines = $order->details->filter(fn (ProductionOrderDetail $detail): bool => (int) $detail->raw_material_id === $row['raw_material_id']);
        $quantity = $this->calculator->sum($lines->map(fn (ProductionOrderDetail $detail): string => (string) ($detail->actual_quantity ?? '0'))->all());

        if (! $this->calculator->isPositive($quantity)) {
            return null;
        }

        $cost = $this->calculator->sum($lines->map(fn (ProductionOrderDetail $detail): string => (string) ($detail->total_cost ?? '0'))->all());
        $unitCost = $this->calculator->div($cost, $quantity, 8);

        return $this->calculator->round(
            $this->calculator->mul($this->calculator->sub($row['consumed'], $row['planned'], 4), $unitCost, 8),
            4,
        );
    }

    /**
     * Lo que costó el exceso de un producto: (gastado − planeado) × su costo por kilo en la orden, el de sus líneas
     * (`ProductLineCosts`: costo ÷ lo que salió de sus fuentes, con lo que volvió al saldo ya descontado). Null si alguna
     * línea del producto no tiene costo por kilo (por ejemplo, un lote sin peso por galón): el exceso es del producto
     * entero, y calcularlo con solo una parte de sus líneas mostraría una cifra que parece completa y no lo es.
     * `$lineCosts` es el resultado de `ProductLineCosts::forOrder`, si ya se calculó; sin él, se calcula aquí.
     *
     * @param  ProductConsumptionRow  $row
     * @param  array<int, array{covered_kg: string, used_kg: string, has_unknown_kg: bool, cost: string|null, unit_cost: string|null}>|null  $lineCosts
     */
    public function productExtraCost(ProductionOrder $order, array $row, ?array $lineCosts = null): ?string
    {
        $lineIds = $order->productLines
            ->filter(fn (ProductionOrderProductLine $line): bool => (int) $line->product_id === $row['product_id'])
            ->map(fn (ProductionOrderProductLine $line): int => (int) $line->id)
            ->all();
        $costs = array_intersect_key($lineCosts ?? $this->productLineCosts->forOrder($order, true), array_flip($lineIds));

        // Sin el costo por kilo de todas sus líneas no hay cifra: ni parcial ni estimada.
        if ($costs === [] || in_array(null, array_column($costs, 'unit_cost'), true)) {
            return null;
        }

        // Con varias líneas, el costo por kilo ponderado: el costo total sobre los kilos que salieron de sus fuentes.
        $cost = $this->calculator->sum(array_map(fn (array $costs): string => (string) $costs['cost'], $costs), 8);
        $kilos = $this->calculator->sum(array_column($costs, 'used_kg'), 8);

        if (! $this->calculator->isPositive($kilos)) {
            return null;
        }

        return $this->calculator->round(
            $this->calculator->mul($this->calculator->sub($row['consumed'], $row['planned'], 4), $this->calculator->div($cost, $kilos, 8), 8),
            4,
        );
    }

    /**
     * «S-7: 55,6 kg de 38,65 planeados (+43,9%)» o «ESMALTE BLANCO: 510 kg de 462,3 planeados (+10,3%)».
     *
     * @param  ConsumptionRow|ProductConsumptionRow  $row
     */
    public function summary(array $row): string
    {
        return sprintf(
            '%s: %s%s de %s planeados (+%s)',
            $this->label($row),
            NumberFormat::quantity($row['consumed']),
            $row['unit'] !== null ? " {$row['unit']}" : '',
            NumberFormat::quantity($row['planned']),
            $this->excessLabel($row),
        );
    }

    /**
     * El exceso con un decimal: con dos se lee mal, y sin decimales un 10,3 % parecería estar en el límite.
     *
     * @param  ConsumptionRow|ProductConsumptionRow  $row
     */
    public function excessLabel(array $row): string
    {
        return NumberFormat::percent($this->calculator->round($row['excess_percentage'], 1));
    }

    /**
     * El código de la MP o el nombre del producto.
     *
     * @param  ConsumptionRow|ProductConsumptionRow  $row
     */
    public function label(array $row): string
    {
        return isset($row['product_id'])
            ? ($row['name'] ?? "PT #{$row['product_id']}")
            : ($row['code'] ?? "MP #{$row['raw_material_id']}");
    }

    /** @param  ConsumptionRow|ProductConsumptionRow  $row */
    private function reasonRequiredMessage(array $row): string
    {
        return __(':summary. Se pasó más del :tolerance de lo planeado: escribe el motivo.', [
            'summary' => $this->summary($row),
            'tolerance' => NumberFormat::percent($row['tolerance_percentage']),
        ]);
    }
}
