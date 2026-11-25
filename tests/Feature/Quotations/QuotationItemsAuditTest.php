<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\QuotationService;
use App\Support\LinePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->client = Client::factory()->create();

    $variant = fn (string $product, string $presentation): ProductVariant => ProductVariant::factory()->create([
        'product_id' => Product::factory()->create(['name' => $product, 'sales_margin' => 25])->id,
        'presentation_label' => $presentation,
        'current_price' => 100000,
        'is_active' => true,
    ]);

    $this->blanco = $variant('ESMALTE BLANCO', 'GALÓN');
    $this->negro = $variant('ESMALTE NEGRO', 'CUÑETE');
    $this->rojo = $variant('ESMALTE ROJO', 'GALÓN');
});

function quotationLine(ProductVariant $variant, int $quantity, ?string $color = null): array
{
    return ['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'color' => $color];
}

function quotationData(Client $client, array $items): array
{
    return ['client_id' => $client->id, 'items' => $items];
}

function quotationItemLogs(): Collection
{
    return Activity::query()->where('subject_type', QuotationItem::class)->orderBy('id')->get();
}

test('crear la cotización no registra sus líneas: la cotización ya deja su entrada', function (): void {
    $quotation = app(QuotationService::class)->create(
        quotationData($this->client, [quotationLine($this->blanco, 2), quotationLine($this->negro, 1)]),
        $this->user,
    );

    expect($quotation->items)->toHaveCount(2)
        ->and(quotationItemLogs())->toBeEmpty()
        ->and(Activity::query()->where('subject_type', Quotation::class)->where('event', 'created')->count())->toBe(1);
});

test('editar conserva las líneas iguales y registra solo lo que cambió, se agregó o se quitó', function (): void {
    $service = app(QuotationService::class);
    $quotation = $service->create(quotationData($this->client, [
        quotationLine($this->blanco, 2),
        quotationLine($this->negro, 1),
        quotationLine($this->rojo, 4, 'RAL 3020'),
    ]), $this->user);
    $originalIds = $quotation->items->pluck('id', 'product_variant_id');

    // Se quita la del medio, cambia la cantidad de la última y se agrega una al final.
    $service->update($quotation, quotationData($this->client, [
        quotationLine($this->blanco, 2),
        quotationLine($this->rojo, 5, 'RAL 3020'),
        quotationLine($this->negro, 3),
    ]));

    $items = $quotation->fresh()->items()->orderBy('sort_order')->get();
    $logs = quotationItemLogs();
    $number = $quotation->quotation_number;

    expect($items->pluck('product_variant_id')->all())->toBe([$this->blanco->id, $this->rojo->id, $this->negro->id])
        // Las que siguen conservan su fila; la negra es nueva porque cambió de lugar respecto a la roja.
        ->and($items[0]->id)->toBe($originalIds[$this->blanco->id])
        ->and($items[1]->id)->toBe($originalIds[$this->rojo->id])
        ->and($logs->pluck('event')->all())->toBe(['deleted', 'updated', 'created'])
        ->and($logs[0]->description)->toBe("Línea de cotización \"{$number} · ESMALTE NEGRO · CUÑETE\" eliminada")
        ->and($logs[1]->description)->toBe("Línea de cotización \"{$number} · ESMALTE ROJO · GALÓN · RAL 3020\" actualizada")
        // Solo la cantidad: el subtotal es calculado y el orden no se registra.
        ->and($logs[1]->properties['attributes'])->toBe(['quantity' => 5])
        ->and($logs[2]->properties['attributes'])->toMatchArray(['product_variant_id' => $this->negro->id, 'quantity' => 3])
        ->and($logs->every(fn (Activity $log): bool => $log->log_name === 'cotizaciones'))->toBeTrue();
});

test('guardar sin cambios en las líneas no deja registros de líneas', function (): void {
    $service = app(QuotationService::class);
    $data = quotationData($this->client, [quotationLine($this->blanco, 2), quotationLine($this->negro, 1)]);
    $quotation = $service->create($data, $this->user);

    $service->update($quotation, $data);

    expect(quotationItemLogs())->toBeEmpty();
});

test('el emparejado sigue la secuencia común y prefiere las líneas idénticas', function (): void {
    // Dos líneas de la misma presentación: la nueva idéntica se queda con la vieja idéntica, no con la primera.
    $old = [['a', 'a|1'], ['a', 'a|2'], ['b', 'b|1']];
    $new = [['a', 'a|2'], ['b', 'b|1']];

    expect(LinePairing::pair($old, $new))->toBe([0 => 1, 1 => 2])
        ->and(LinePairing::pair([], [['a', 'a|1']]))->toBe([])
        ->and(LinePairing::pair([['a', 'a|1']], [['b', 'b|1']]))->toBe([]);
});
