<?php

declare(strict_types=1);

use App\Models\FinishedInventory;
use App\Models\Formula;
use App\Models\FormulaDetail;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\RawMaterialFamily;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Audit\AuditLogPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Activity::query()->delete();
});

/** Un registro con las propiedades dadas, presentado como lo recibe la pantalla. */
function presentLog(array $attributes): array
{
    $log = Activity::create([
        'log_name' => 'materias_primas',
        'description' => 'Registro de prueba',
        'event' => 'updated',
        ...$attributes,
    ]);

    return app(AuditLogPresenter::class)->present(collect([$log->fresh()]))->sole();
}

/** @return array<string, array<string, mixed>> los cambios por campo */
function changesByField(array $presented): array
{
    return collect($presented['changes'])->keyBy('field')->all();
}

test('muestra cada campo en español, con su valor formateado según el cast y los IDs con su nombre', function (): void {
    $before = RawMaterialCategory::factory()->create(['name' => 'EPOXI', 'family_id' => RawMaterialFamily::factory()->create(['name' => 'RESINA'])->id]);
    $after = RawMaterialCategory::factory()->create(['name' => 'BASE AGUA', 'family_id' => RawMaterialFamily::factory()->create(['name' => 'SOLVENTE'])->id]);

    $presented = presentLog([
        'subject_type' => RawMaterial::class,
        'subject_id' => 1,
        'properties' => [
            'old' => ['category_id' => $before->id, 'current_price' => '1250.5000', 'is_active' => true, 'code' => 'S - 12'],
            'attributes' => ['category_id' => $after->id, 'current_price' => '1300.0000', 'is_active' => false, 'code' => 'S - 12'],
        ],
    ]);

    $changes = changesByField($presented);

    expect($presented['detailType'])->toBe('changes')
        ->and($presented['module'])->toBe('Materias primas')
        ->and($presented['eventLabel'])->toBe('Actualizado')
        // El código no cambió: no se lista.
        ->and($changes)->toHaveKeys(['category_id', 'current_price', 'is_active'])->not->toHaveKey('code')
        ->and($changes['category_id'])->toMatchArray(['label' => 'Categoría', 'before' => 'RESINA · EPOXI', 'after' => 'SOLVENTE · BASE AGUA', 'kind' => 'changed'])
        ->and($changes['current_price'])->toMatchArray(['label' => 'Precio actual', 'before' => '1.250,5', 'after' => '1.300'])
        ->and($changes['is_active'])->toMatchArray(['label' => 'Activo', 'before' => 'Sí', 'after' => 'No']);
});

test('las fechas con hora salen en hora de planta y las de solo día sin correrse', function (): void {
    $presented = presentLog([
        'log_name' => 'pedidos',
        'subject_type' => SalesOrder::class,
        'subject_id' => 1,
        'properties' => ['attributes' => [
            'approved_at' => '2026-11-24T20:20:41.000000Z',
            'required_date' => '2026-11-24T00:00:00.000000Z',
            'hold_reason' => null,
        ]],
    ]);

    $changes = changesByField($presented);

    expect($changes['approved_at'])->toMatchArray(['label' => 'Aprobado el', 'after' => '24/11/2026 15:20', 'kind' => 'set'])
        ->and($changes['required_date']['after'])->toBe('24/11/2026')
        ->and($changes['hold_reason'])->toMatchArray(['label' => 'Motivo de la retención', 'after' => null]);
});

test('un enum sale con su etiqueta y un registro borrado, por su número', function (): void {
    $presented = presentLog([
        'log_name' => 'ordenes_produccion',
        'subject_type' => ProductionOrder::class,
        'subject_id' => 1,
        'event' => 'deleted',
        'properties' => ['old' => ['status' => 'completed', 'product_id' => 999_999]],
    ]);

    $changes = changesByField($presented);

    expect($presented['module'])->toBe('Órdenes de producción')
        ->and($changes['status'])->toMatchArray(['label' => 'Estado', 'before' => 'Completada', 'after' => null, 'kind' => 'unset'])
        ->and($changes['product_id']['before'])->toBe('#999999');
});

test('el nombre de un registro que se arma con un accessor se resuelve igual', function (): void {
    $variant = ProductVariant::factory()->create(['presentation_label' => 'GALÓN']);

    $presented = presentLog([
        'log_name' => 'inventario_terminado',
        'subject_type' => FinishedInventory::class,
        'subject_id' => 1,
        'event' => 'created',
        'properties' => ['attributes' => ['product_variant_id' => $variant->id]],
    ]);

    expect(changesByField($presented)['product_variant_id']['after'])->toBe($variant->product->name.' · GALÓN');
});

test('los registros manuales salen como una lista de datos con su etiqueta', function (): void {
    $failedLogin = presentLog([
        'log_name' => 'auth',
        'event' => 'failed_login',
        'properties' => ['email' => 'nadie@pintech.test', 'ip' => '10.0.0.1', 'user_agent' => 'Safari'],
    ]);

    $roleChanged = presentLog([
        'log_name' => 'security',
        'event' => 'role_updated',
        'subject_type' => User::class,
        'subject_id' => 1,
        'properties' => ['old_role' => null, 'new_role' => 'admin', 'added_permissions' => ['users.view'], 'source' => 'console'],
    ]);

    expect($failedLogin['detailType'])->toBe('properties')
        ->and($failedLogin['module'])->toBe('Inicio de sesión')
        ->and($failedLogin['eventLabel'])->toBe('Inicio de sesión fallido')
        ->and(collect($failedLogin['changes'])->pluck('after', 'label')->all())->toBe([
            'Correo electrónico' => 'nadie@pintech.test',
            'Dirección IP' => '10.0.0.1',
            'Navegador' => 'Safari',
        ])
        ->and(collect($roleChanged['changes'])->pluck('after', 'label')->all())->toBe([
            'Rol anterior' => 'Sin rol',
            'Rol nuevo' => 'Administrador',
            'Permisos añadidos' => 'Ver usuarios',
            'Origen' => 'Consola',
        ]);
});

test('un enum de enteros guardado como texto sale con su etiqueta, sin romper la página', function (): void {
    $presented = presentLog([
        'log_name' => 'cotizaciones',
        'subject_type' => Quotation::class,
        'subject_id' => 1,
        'properties' => ['old' => ['validity_days' => '30'], 'attributes' => ['validity_days' => 45]],
    ]);

    expect(changesByField($presented)['validity_days'])->toMatchArray(['before' => '30 días', 'after' => '45 días']);
});

test('la fórmula de otro registro se nombra con su producto y su versión', function (): void {
    $formula = Formula::factory()->create(['version' => 2]);

    $presented = presentLog([
        'log_name' => 'formulas',
        'subject_type' => FormulaDetail::class,
        'subject_id' => 1,
        'event' => 'created',
        'properties' => ['attributes' => ['formula_id' => $formula->id]],
    ]);

    expect(changesByField($presented)['formula_id']['after'])->toBe($formula->product->name.' v2');
});

test('un módulo o evento sin etiqueta se muestra con su nombre', function (): void {
    $presented = presentLog(['log_name' => 'inventario', 'event' => 'otro_evento', 'properties' => []]);

    expect($presented['module'])->toBe('inventario')
        ->and($presented['eventLabel'])->toBe('otro_evento')
        ->and($presented['changes'])->toBe([]);
});

test('la pantalla recibe los registros presentados y los filtros con su etiqueta', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Activity::query()->delete();

    Activity::create(['log_name' => 'usuarios', 'description' => 'Usuario creado', 'event' => 'created']);
    Activity::create(['log_name' => 'ordenes_produccion', 'description' => 'OP creada', 'event' => 'created']);
    Activity::create(['log_name' => 'auth', 'description' => 'Intento fallido', 'event' => 'failed_login']);

    $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AuditLogs/Index')
            ->where('logs.data.0.module', 'Inicio de sesión')
            ->missing('logs.data.0.properties')
            ->where('options.logNames', [
                ['value' => 'auth', 'label' => 'Inicio de sesión'],
                ['value' => 'ordenes_produccion', 'label' => 'Órdenes de producción'],
                ['value' => 'usuarios', 'label' => 'Usuarios'],
            ])
            ->where('options.events', [
                ['value' => 'created', 'label' => 'Creado'],
                ['value' => 'failed_login', 'label' => 'Inicio de sesión fallido'],
            ])
        );
});

test('las categorías se nombran con su familia sin una consulta por cada una', function () {
    $categories = collect(['EPOXI', 'ALQUIDICA', 'BASE AGUA'])->map(fn (string $name) => RawMaterialCategory::factory()->create([
        'name' => $name, 'family_id' => RawMaterialFamily::factory()->create(['name' => "RESINA {$name}"])->id,
    ]));
    $logs = $categories->map(fn (RawMaterialCategory $category) => Activity::create([
        'log_name' => 'materias_primas', 'description' => 'Prueba', 'event' => 'created',
        'subject_type' => RawMaterial::class, 'subject_id' => 1,
        'properties' => ['attributes' => ['category_id' => $category->id]],
    ])->fresh());

    DB::enableQueryLog();
    $presented = app(AuditLogPresenter::class)->present($logs);
    $familyReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "raw_material_families"'))->count();

    expect($presented->map(fn (array $log): string => $log['changes'][0]['after'])->all())
        ->toBe(['RESINA EPOXI · EPOXI', 'RESINA ALQUIDICA · ALQUIDICA', 'RESINA BASE AGUA · BASE AGUA'])
        ->and($familyReads)->toBe(1);
});
