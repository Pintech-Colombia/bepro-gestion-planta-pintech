<?php

declare(strict_types=1);

use App\Enums\RawMaterialType;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Import\BaseDataImporter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

// Carga inicial de datos reales desde la plantilla canónica (`php artisan import:base-data`).

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['email' => 'carga@pintech.test']);
    $this->factory = Warehouse::factory()->factory()->create();
    UnitOfMeasure::factory()->create(['code' => 'kg']);
    UnitOfMeasure::factory()->create(['code' => 'u']);
});

/**
 * Escribe una plantilla canónica con las filas dadas por hoja; las hojas que no se den van solo con encabezados.
 *
 * @param  array<string, list<list<string>>>  $rows
 */
function canonicalTemplate(array $rows): string
{
    $book = new Spreadsheet;
    $book->removeSheetByIndex(0);

    foreach (BaseDataImporter::SHEETS as $title => $columns) {
        $sheet = $book->createSheet()->setTitle($title);
        $sheet->fromArray([$columns, ...($rows[$title] ?? [])], null, 'A1', true);
    }

    $path = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

/** @return array<string, list<list<string>>> */
function fullTemplateRows(): array
{
    return [
        'Familias' => [['ADITIVO', ''], ['ENVASE Y EMPAQUE', '']],
        'Categorias' => [
            ['ADITIVO - SECANTE', 'ADITIVO - SECANTE', 'ADITIVO', 'chemical'],
            ['ENVASES METALICOS', 'ENVASES METALICOS', 'ENVASE Y EMPAQUE', 'container'],
            ['AGUA', 'AGUA', 'ADITIVO', 'chemical'],
        ],
        'Proveedores' => [['QUIMICA COSMOS', 'Razón social sin verificar'], ['ENVMETALPLAST', '']],
        'Materias primas' => [
            ['ABS - 10', 'ADITIVO - SECANTE', 'kg', '0', '90', 'SI', ''],
            ['ENV-MET-GTD', 'ENVASES METALICOS', 'u', '0', '90', 'SI', ''],
            ['AGUA', 'AGUA', 'kg', '0', '90', 'NO', '1000'],
        ],
        'Lotes iniciales' => [
            ['ABS - 10', '41.8615', '14950', '2025-01-01', '', 'QUIMICA COSMOS'],
            ['ABS - 10', '10', '15200', '2026-10-01', '2027-06-01', ''],
            ['ENV-MET-GTD', '2197', '5000', '2026-07-01', '', 'ENVMETALPLAST'],
        ],
    ];
}

function importTemplate(string $path, array $options = []): PendingCommand
{
    return test()->artisan('import:base-data', ['file' => $path, '--user' => 'carga@pintech.test', ...$options]);
}

it('carga familias, categorías, proveedores, materias primas y lotes como entradas reales', function () {
    importTemplate(canonicalTemplate(fullTemplateRows()))->assertSuccessful();

    $secante = RawMaterialCategory::query()->where('code', 'ADITIVO - SECANTE')->sole();
    $material = RawMaterial::query()->where('code', 'ABS - 10')->sole();
    $batches = InventoryBatch::query()->where('raw_material_id', $material->id)->orderBy('entry_date')->get();

    expect(RawMaterialFamily::query()->count())->toBe(2)
        ->and($secante->family->name)->toBe('ADITIVO')
        ->and(RawMaterialCategory::query()->where('code', 'ENVASES METALICOS')->sole()->type)->toBe(RawMaterialType::Container)
        ->and(Supplier::query()->where('name', 'QUIMICA COSMOS')->sole()->notes)->toBe('Razón social sin verificar')
        // Dos lotes de la misma MP, cada uno con su fecha, precio y proveedor.
        ->and($batches)->toHaveCount(2)
        ->and($batches[0]->remaining_quantity)->toBe('41.8615')
        ->and($batches[0]->unit_price)->toBe('14950.0000')
        ->and($batches[0]->entry_date->toDateString())->toBe('2025-01-01')
        ->and($batches[0]->supplier->name)->toBe('QUIMICA COSMOS')
        ->and($batches[0]->lot_number)->toStartWith('MP-')
        ->and($batches[1]->expiry_date->toDateString())->toBe('2027-06-01')
        ->and($batches[1]->supplier_id)->toBeNull()
        ->and(InventoryBatch::query()->pluck('warehouse_id')->unique()->all())->toBe([$this->factory->id])
        ->and(InventoryMovement::query()->where('notes', BaseDataImporter::BATCH_NOTE)->count())->toBe(3)
        // Sin control de inventario: sin lotes y con su precio de referencia a mano.
        ->and(RawMaterial::query()->where('code', 'AGUA')->sole()->current_price)->toBe('1000.0000')
        ->and(Activity::query()->where('subject_type', RawMaterial::class)->where('subject_id', $material->id)->value('causer_id'))
        ->toBe($this->user->id);
});

it('en simulación no guarda nada', function () {
    importTemplate(canonicalTemplate(fullTemplateRows()), ['--dry-run' => true])
        ->expectsOutputToContain('Simulación correcta')
        ->assertSuccessful();

    expect(RawMaterialFamily::query()->count())->toBe(0)
        ->and(RawMaterial::query()->count())->toBe(0)
        ->and(InventoryBatch::query()->count())->toBe(0);
});

it('con un error no guarda nada y dice hoja y fila', function () {
    $rows = fullTemplateRows();
    $rows['Lotes iniciales'][] = ['ABS - 99', '5', '1000', '2026-10-01', '', ''];
    $rows['Lotes iniciales'][] = ['ENV-MET-GTD', '-3', '5000', '2026-10-01', '', ''];

    importTemplate(canonicalTemplate($rows))
        ->expectsOutputToContain('Lotes iniciales, fila 5: La materia prima no existe')
        ->expectsOutputToContain('Lotes iniciales, fila 6:')
        ->expectsOutputToContain('no se guardó nada')
        ->assertFailed();

    expect(RawMaterialFamily::query()->count())->toBe(0)
        ->and(InventoryBatch::query()->count())->toBe(0);
});

it('se puede volver a correr: no duplica nada ni carga lotes a una MP que ya tiene', function () {
    $path = canonicalTemplate(fullTemplateRows());
    importTemplate($path)->assertSuccessful();

    importTemplate($path)->assertSuccessful();

    expect(RawMaterialFamily::query()->count())->toBe(2)
        ->and(RawMaterialCategory::query()->count())->toBe(3)
        ->and(Supplier::query()->count())->toBe(2)
        ->and(RawMaterial::query()->count())->toBe(3)
        ->and(InventoryBatch::query()->count())->toBe(3);
});

it('rechaza lotes de una MP sin control de inventario y precios a mano en una que sí controla', function () {
    $rows = fullTemplateRows();
    $rows['Materias primas'][0][6] = '500';
    $rows['Lotes iniciales'][] = ['AGUA', '10', '1000', '2026-10-01', '', ''];

    importTemplate(canonicalTemplate($rows))
        ->expectsOutputToContain('Materias primas, fila 2:')
        ->expectsOutputToContain('La materia prima no controla inventario: no lleva lotes.')
        ->assertFailed();
});

it('exige una sola fábrica activa para los lotes', function () {
    Warehouse::factory()->factory()->create();

    importTemplate(canonicalTemplate(fullTemplateRows()))
        ->expectsOutputToContain('Debe haber exactamente una bodega de tipo fábrica activa')
        ->assertFailed();
});

it('falla si falta una hoja o el usuario no existe', function () {
    $book = new Spreadsheet;
    $path = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
    (new Xlsx($book))->save($path);

    importTemplate($path)->expectsOutputToContain('Falta la hoja «Familias».')->assertFailed();
    $this->artisan('import:base-data', ['file' => $path, '--user' => 'nadie@pintech.test'])->assertFailed();
});

it('carga el mismo nombre de categoría en dos familias y la MP la elige por código', function () {
    $rows = fullTemplateRows();
    $rows['Familias'][] = ['RESINA', ''];
    $rows['Familias'][] = ['SOLVENTE', ''];
    $rows['Categorias'][] = ['RESINA - BASE AGUA', 'BASE AGUA', 'RESINA', 'chemical'];
    $rows['Categorias'][] = ['SOLVENTE - BASE AGUA', 'BASE AGUA', 'SOLVENTE', 'chemical'];
    $rows['Materias primas'][] = ['ABA - 5', 'SOLVENTE - BASE AGUA', 'kg', '0', '90', 'SI', ''];

    importTemplate(canonicalTemplate($rows))->assertSuccessful();

    expect(RawMaterialCategory::query()->where('name', 'BASE AGUA')->count())->toBe(2)
        ->and(RawMaterial::query()->where('code', 'ABA - 5')->sole()->category->family->name)->toBe('SOLVENTE');
});
