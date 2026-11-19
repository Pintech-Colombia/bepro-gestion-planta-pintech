<?php

declare(strict_types=1);

namespace App\Actions\Formulas\Concerns;

use App\Models\Product;
use App\Services\Formulas\FormulaProductGraph;
use App\Services\FormulaService;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * Toda escritura de fórmulas bloquea primero la fila del producto, dentro de la transacción de quien llama. Así se ponen
 * en fila entre ellas (versión, referencia, líneas) y con lo que lee la fórmula de un producto: crear una OP toma un
 * bloqueo compartido sobre el mismo producto. Orden de bloqueo, siempre: producto, fórmula y, al guardar líneas, sus
 * materias primas en modo compartido.
 */
trait LocksFormulaProduct
{
    /**
     * @return bool si el producto sigue activo.
     */
    private function lockProduct(int $productId): bool
    {
        return (bool) Product::query()->whereKey($productId)->lockForUpdate()->value('is_active');
    }

    /**
     * Con el producto inactivo, sus fórmulas solo se desactivan o se eliminan: no se crean (D-FOR3), no se editan, no se
     * activan ni cambian de referencia (P9-13). Si el producto vuelve, se reactiva primero.
     *
     * @throws DomainException
     */
    private function lockActiveProduct(int $productId): void
    {
        if (! $this->lockProduct($productId)) {
            throw new DomainException($this->inactiveProductMessage());
        }
    }

    /**
     * Vuelve a validar FOR-02 con las materias primas de las líneas bloqueadas en modo compartido: el request lo revisó sin
     * bloqueo, y la unidad de una materia prima sin uso pudo cambiar entre tanto (UpdateRawMaterialAction la bloquea para
     * escribir). El error queda en la misma clave que el del request.
     *
     * @param  array<int|string, mixed>  $details
     *
     * @throws ValidationException
     */
    private function lockLineMaterialsAndCheckUnits(array $details): void
    {
        $errors = app(FormulaService::class)->unconvertibleLines($details, lockMaterials: true);

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)
                ->mapWithKeys(fn (string $message, int|string $index): array => ["details.{$index}.unit_of_measure_id" => $message])
                ->all());
        }
    }

    /**
     * Que la fórmula activa de `$productId` con estas líneas no forme un ciclo de productos (D1): ni el producto en su
     * propia fórmula ni a través de otro. 422 en `details` con el camino. Sin bloquear los productos ingrediente: dos
     * guardados cruzados exactamente a la vez son un caso límite aceptado (CL-56); el recálculo en cadena tiene tope.
     *
     * @param  iterable<array<string, mixed>>  $details
     *
     * @throws ValidationException
     */
    private function ensureNoProductCycle(int $productId, iterable $details, ?int $exceptFormulaId = null): void
    {
        $ingredientIds = collect($details)->pluck('product_id')->filter(fn ($id): bool => is_numeric($id))->all();
        $cycle = app(FormulaProductGraph::class)->cycleFor($productId, $ingredientIds, $exceptFormulaId);

        if ($cycle !== null) {
            throw ValidationException::withMessages([
                'details' => __('Se formaría un ciclo: :path. Un producto no puede llevarse a sí mismo, ni a través de otro.', [
                    'path' => implode(' → ', $cycle),
                ]),
            ]);
        }
    }

    private function inactiveProductMessage(): string
    {
        return __('El producto está inactivo: sus fórmulas solo se pueden desactivar o eliminar. Reactiva el producto para cambiarlas.');
    }
}
