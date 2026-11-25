<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\InventoryMovementType;
use App\Enums\NumberType;
use App\Enums\RawMaterialType;
use App\Enums\TextFormat;
use App\Enums\WarehouseType;
use App\Models\InventoryBatch;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\PlantDate;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga inicial de datos reales desde la plantilla canónica (un .xlsx con una hoja por entidad), que arma el script de
 * limpieza a partir del Excel de la jefa. Crea familias, categorías, proveedores, materias primas y sus lotes iniciales.
 *
 * Lo que ya existe no se toca ni se duplica (familia, categoría y proveedor por nombre sin mayúsculas; categoría también
 * por código; MP por código), y una MP que ya tenía lotes no recibe más: se puede volver a correr. Los lotes entran
 * como entradas reales por InventoryService (número MP-AAAA-NNNN, movimiento, precio de referencia y alertas).
 *
 * No abre la transacción: quien lo llama la abre y decide si confirma o, con errores o en simulación, deshace.
 */
final class BaseDataImporter
{
    /** Hojas y columnas de la plantilla, en este orden: cada hoja usa lo que crean las anteriores. */
    public const SHEETS = [
        'Familias' => ['nombre', 'descripcion'],
        'Categorias' => ['codigo', 'nombre', 'familia', 'tipo'],
        'Proveedores' => ['nombre', 'notas'],
        // `categoria` es el código de la categoría.
        'Materias primas' => ['codigo', 'categoria', 'unidad', 'stock_minimo', 'dias_alerta', 'controla_inventario', 'precio_manual', 'sustancia_controlada'],
        'Lotes iniciales' => ['codigo_mp', 'cantidad', 'precio', 'fecha_entrada', 'vencimiento', 'proveedor'],
    ];

    /**
     * Columnas que pueden faltar (una plantilla anterior no las trae): vacías si faltan. `tolerancia_consumo` es el % de
     * la familia; vacío, el general.
     */
    public const OPTIONAL_COLUMNS = [
        'Familias' => ['tolerancia_consumo'],
    ];

    public const BATCH_NOTE = 'Carga inicial de inventario';

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, array{created: int, existing: int}> */
    private array $counts = [];

    /** MP que ya tenían lotes antes de esta carga: no reciben lotes nuevos. @var array<int, bool> */
    private array $materialsWithPreviousBatches = [];

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @return array<string, list<array{row: int, data: array<string, string>}>>
     */
    public function read(string $path): array
    {
        $book = IOFactory::load($path);
        $sheets = [];

        foreach (self::SHEETS as $title => $columns) {
            $sheet = $book->getSheetByName($title);

            if ($sheet === null) {
                throw ValidationException::withMessages(['archivo' => "Falta la hoja «{$title}»."]);
            }

            $rows = $sheet->toArray(null, true, false, false);
            $header = array_map(fn ($cell): string => trim((string) $cell), $rows[0] ?? []);
            $missing = array_diff($columns, $header);

            if ($missing !== []) {
                throw ValidationException::withMessages(['archivo' => "A la hoja «{$title}» le faltan las columnas: ".implode(', ', $missing).'.']);
            }

            $sheets[$title] = [];

            foreach (array_slice($rows, 1) as $index => $cells) {
                $data = [];

                foreach ([...$columns, ...(self::OPTIONAL_COLUMNS[$title] ?? [])] as $column) {
                    $position = array_search($column, $header, true);
                    $data[$column] = $position === false ? '' : $this->cellText($cells[$position] ?? null);
                }

                if (implode('', $data) !== '') {
                    $sheets[$title][] = ['row' => $index + 2, 'data' => $data];
                }
            }
        }

        return $sheets;
    }

    /**
     * @param  array<string, list<array{row: int, data: array<string, string>}>>  $sheets
     * @return array{counts: array<string, array{created: int, existing: int}>, errors: list<string>}
     */
    public function import(array $sheets, User $user): array
    {
        $this->errors = [];
        $this->counts = array_fill_keys(array_keys(self::SHEETS), ['created' => 0, 'existing' => 0]);

        $warehouse = $this->factoryWarehouse();

        foreach ($sheets['Familias'] as $row) {
            $this->importFamily($row['row'], $row['data']);
        }
        foreach ($sheets['Categorias'] as $row) {
            $this->importCategory($row['row'], $row['data']);
        }
        foreach ($sheets['Proveedores'] as $row) {
            $this->importSupplier($row['row'], $row['data']);
        }
        foreach ($sheets['Materias primas'] as $row) {
            $this->importMaterial($row['row'], $row['data']);
        }
        foreach ($sheets['Lotes iniciales'] as $row) {
            if ($warehouse !== null) {
                $this->importBatch($row['row'], $row['data'], $warehouse, $user);
            }
        }

        return ['counts' => $this->counts, 'errors' => $this->errors];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importFamily(int $row, array $data): void
    {
        $data['nombre'] = TextFormat::Name->normalize($data['nombre']);

        if ($this->findByName(RawMaterialFamily::query(), $data['nombre']) !== null) {
            $this->counts['Familias']['existing']++;

            return;
        }

        if (! $this->valid('Familias', $row, $data, [
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'tolerancia_consumo' => ['nullable', ...NumberType::Percentage->rules('100'), 'min:0'],
        ])) {
            return;
        }

        RawMaterialFamily::create([
            'name' => $data['nombre'],
            'description' => $data['descripcion'] !== '' ? $data['descripcion'] : null,
            'consumption_tolerance_percentage' => $data['tolerancia_consumo'] !== '' ? $data['tolerancia_consumo'] : null,
            'is_active' => true,
        ]);
        $this->counts['Familias']['created']++;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importCategory(int $row, array $data): void
    {
        $data['codigo'] = TextFormat::Code->normalize($data['codigo']);
        $data['nombre'] = TextFormat::Name->normalize($data['nombre']);
        $family = $this->findByName(RawMaterialFamily::query(), TextFormat::Name->normalize($data['familia']));

        // El código es único en toda la tabla; el nombre, solo dentro de su familia («BASE AGUA» en Resina y en Solvente).
        $exists = RawMaterialCategory::query()
            ->where('code', $data['codigo'])
            ->when($family !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('family_id', $family->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['nombre'])])))
            ->exists();

        if ($exists) {
            $this->counts['Categorias']['existing']++;

            return;
        }

        if (! $this->valid('Categorias', $row, $data, [
            'codigo' => ['bail', 'required', 'string', 'max:50', ...TextFormat::Code->rules()],
            'nombre' => ['required', 'string', 'max:100'],
            'familia' => ['required', $this->mustExist($family, 'La familia no existe en la hoja Familias ni en el sistema.')],
            'tipo' => ['required', Rule::enum(RawMaterialType::class)],
        ])) {
            return;
        }

        RawMaterialCategory::create([
            'code' => $data['codigo'],
            'name' => $data['nombre'],
            'family_id' => $family?->id,
            'type' => $data['tipo'],
            'is_active' => true,
        ]);
        $this->counts['Categorias']['created']++;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importSupplier(int $row, array $data): void
    {
        $data['nombre'] = TextFormat::Name->normalize($data['nombre']);

        if ($this->findByName(Supplier::query(), $data['nombre']) !== null) {
            $this->counts['Proveedores']['existing']++;

            return;
        }

        if (! $this->valid('Proveedores', $row, $data, [
            'nombre' => ['required', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ])) {
            return;
        }

        Supplier::create([
            'name' => $data['nombre'],
            'notes' => $data['notas'] !== '' ? $data['notas'] : null,
            'is_active' => true,
        ]);
        $this->counts['Proveedores']['created']++;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importMaterial(int $row, array $data): void
    {
        $data['codigo'] = TextFormat::Code->normalize($data['codigo']);
        $existing = RawMaterial::query()->where('code', $data['codigo'])->first();

        if ($existing !== null) {
            $this->counts['Materias primas']['existing']++;
            $this->materialsWithPreviousBatches[$existing->id] = $existing->inventoryBatches()->exists();

            return;
        }

        // Por código: el nombre de la categoría solo es único dentro de su familia.
        $category = RawMaterialCategory::query()->where('code', TextFormat::Code->normalize($data['categoria']))->first();
        $unit = UnitOfMeasure::query()->where('code', TextFormat::UnitCode->normalize($data['unidad']))->first();
        $tracksInventory = mb_strtoupper($data['controla_inventario']) !== 'NO';

        if (! $this->valid('Materias primas', $row, $data, [
            'codigo' => ['bail', 'required', 'string', 'max:50', ...TextFormat::Code->rules()],
            'categoria' => ['required', $this->mustExist($category, 'No hay una categoría con ese código en la hoja Categorias ni en el sistema.')],
            'unidad' => ['required', $this->mustExist($unit, 'La unidad no existe en el sistema.')],
            'stock_minimo' => ['bail', 'required', ...NumberType::Quantity->rules(), 'min:0'],
            'dias_alerta' => ['bail', 'required', 'integer', 'min:0', 'max:'.RawMaterial::MAX_EXPIRY_ALERT_DAYS],
            'controla_inventario' => ['required', Rule::in(['SI', 'NO', 'si', 'no', 'Si', 'No'])],
            // SICOQ: vacío cuenta como NO.
            'sustancia_controlada' => ['nullable', Rule::in(['SI', 'NO', 'si', 'no', 'Si', 'No'])],
            // Sin control de inventario no hay lotes: el precio de referencia se escribe a mano.
            'precio_manual' => $tracksInventory
                ? ['prohibited']
                : ['bail', 'required', ...NumberType::Money->rules(), 'gt:0'],
        ])) {
            return;
        }

        $material = RawMaterial::create([
            'code' => $data['codigo'],
            'category_id' => $category?->id,
            'unit_of_measure_id' => $unit?->id,
            'minimum_stock' => $data['stock_minimo'],
            'alert_days_before_expiry' => (int) $data['dias_alerta'],
            'tracks_inventory' => $tracksInventory,
            'is_controlled_substance' => mb_strtoupper($data['sustancia_controlada']) === 'SI',
            'current_price' => $tracksInventory ? null : $data['precio_manual'],
            'is_active' => true,
        ]);
        $this->materialsWithPreviousBatches[$material->id] = false;
        $this->counts['Materias primas']['created']++;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importBatch(int $row, array $data, Warehouse $warehouse, User $user): void
    {
        $material = RawMaterial::query()->where('code', TextFormat::Code->normalize($data['codigo_mp']))->first();

        if ($material !== null && ($this->materialsWithPreviousBatches[$material->id]
            ?? InventoryBatch::query()->where('raw_material_id', $material->id)->exists())) {
            $this->counts['Lotes iniciales']['existing']++;

            return;
        }

        $supplier = $data['proveedor'] !== ''
            ? $this->findByName(Supplier::query(), TextFormat::Name->normalize($data['proveedor']))
            : null;

        if (! $this->valid('Lotes iniciales', $row, $data, [
            'codigo_mp' => ['required', $this->mustExist($material, 'La materia prima no existe en la hoja Materias primas ni en el sistema.'),
                fn (string $attribute, mixed $value, \Closure $fail) => $material !== null && ! $material->tracks_inventory
                    ? $fail('La materia prima no controla inventario: no lleva lotes.')
                    : null,
            ],
            'cantidad' => ['bail', 'required', ...NumberType::Quantity->rules(), 'gt:0'],
            'precio' => ['bail', 'required', ...NumberType::Money->rules(), 'gt:0'],
            'fecha_entrada' => ['bail', 'required', 'date_format:Y-m-d', PlantDate::make()->notFuture()],
            'vencimiento' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_entrada'],
            'proveedor' => ['nullable', $this->mustExist($supplier, 'El proveedor no existe en la hoja Proveedores ni en el sistema.', optional: true)],
        ])) {
            return;
        }

        $this->inventory->storeMovement([
            'type' => InventoryMovementType::Entry->value,
            'raw_material_id' => $material?->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $data['cantidad'],
            'cost_price' => $data['precio'],
            // También es la fecha de entrada del lote, que ordena el FIFO.
            'movement_date' => $data['fecha_entrada'],
            'expiry_date' => $data['vencimiento'] !== '' ? $data['vencimiento'] : null,
            'supplier_id' => $supplier?->id,
            'notes' => self::BATCH_NOTE,
        ], $user->id);
        $this->counts['Lotes iniciales']['created']++;
    }

    /** La materia prima solo entra a la fábrica (D6): debe haber una sola fábrica activa. */
    private function factoryWarehouse(): ?Warehouse
    {
        $factories = Warehouse::query()->active()->where('type', WarehouseType::Factory->value)->get();

        if ($factories->count() !== 1) {
            $this->errors[] = "Debe haber exactamente una bodega de tipo fábrica activa para los lotes; hay {$factories->count()}.";

            return null;
        }

        return $factories->first();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function findByName(Builder $query, string $name): ?Model
    {
        return $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    private function mustExist(?Model $model, string $message, bool $optional = false): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($model, $message, $optional): void {
            if ($model === null && ! ($optional && ($value === null || $value === ''))) {
                $fail($message);
            }
        };
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $rules
     */
    private function valid(string $sheet, int $row, array $data, array $rules): bool
    {
        $validator = Validator::make(
            array_map(fn (string $value): ?string => $value === '' ? null : $value, $data),
            $rules,
            attributes: array_combine(array_keys($data), array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($data))),
        );

        if ($validator->passes()) {
            return true;
        }

        foreach ($validator->errors()->all() as $message) {
            $this->errors[] = "{$sheet}, fila {$row}: {$message}";
        }

        return false;
    }

    /** Texto de una celda: los números escritos por Excel como flotante se pasan sin ruido de coma flotante. */
    private function cellText(mixed $value): string
    {
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }

        return trim((string) $value);
    }
}
