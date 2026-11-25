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
use Illuminate\Support\Facades\DB;
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

test('con dos líneas de la misma presentación en distinto color, sigue la del mismo color', function (): void {
    $service = app(QuotationService::class);
    $quotation = $service->create(quotationData($this->client, [
        quotationLine($this->blanco, 1, 'ROJO'),
        quotationLine($this->blanco, 1, 'AZUL'),
    ]), $this->user);
    $azulId = $quotation->items->firstWhere('color', 'AZUL')->id;

    // Se quita la roja y se cambia la cantidad de la azul.
    $service->update($quotation, quotationData($this->client, [quotationLine($this->blanco, 2, 'AZUL')]));

    $logs = quotationItemLogs();
    $number = $quotation->quotation_number;

    expect($quotation->fresh()->items->sole()->id)->toBe($azulId)
        ->and($logs->pluck('event')->all())->toBe(['deleted', 'updated'])
        ->and($logs[0]->description)->toBe("Línea de cotización \"{$number} · ESMALTE BLANCO · GALÓN · ROJO\" eliminada")
        ->and($logs[1]->properties['attributes'])->toBe(['quantity' => 2]);
});

test('al cambiar el color, la línea se nombra con el color que tenía', function (): void {
    $service = app(QuotationService::class);
    $quotation = $service->create(quotationData($this->client, [quotationLine($this->blanco, 1, 'ROJO')]), $this->user);

    $service->update($quotation, quotationData($this->client, [quotationLine($this->blanco, 1, 'AZUL')]));

    $log = quotationItemLogs()->sole();

    expect($log->description)->toBe("Línea de cotización \"{$quotation->quotation_number} · ESMALTE BLANCO · GALÓN · ROJO\" actualizada")
        ->and($log->properties['old'])->toBe(['color' => 'ROJO'])
        ->and($log->properties['attributes'])->toBe(['color' => 'AZUL']);
});

test('las líneas nuevas de una edición no consultan su producto ni su presentación una por una', function (): void {
    $service = app(QuotationService::class);
    $quotation = $service->create(quotationData($this->client, [quotationLine($this->blanco, 1)]), $this->user);

    DB::enableQueryLog();
    $service->update($quotation, quotationData($this->client, [
        quotationLine($this->blanco, 1),
        quotationLine($this->negro, 1),
        quotationLine($this->rojo, 1),
    ]));

    // Sin la precarga, el identificador de cada línea nueva leería su presentación y su producto por separado.
    $perLineReads = collect(DB::getQueryLog())->filter(
        fn (array $query): bool => (bool) preg_match('/^select "(presentation_label|name)" from "(product_variants|products)"/', $query['query']),
    );

    expect($perLineReads)->toBeEmpty()
        ->and(quotationItemLogs()->where('event', 'created')->pluck('description')->all())->toBe([
            "Línea de cotización \"{$quotation->quotation_number} · ESMALTE NEGRO · CUÑETE\" creada",
            "Línea de cotización \"{$quotation->quotation_number} · ESMALTE ROJO · GALÓN\" creada",
        ]);
});

test('el emparejado rechaza llaves que no son una lista desde 0', function (): void {
    expect(fn () => LinePairing::pair([1 => ['a', 'a|1']], [['a', 'a|1']]))->toThrow(InvalidArgumentException::class);
});

test('un desempate más importante gana a los que siguen', function (): void {
    // Misma clave en las dos viejas: la nueva coincide en color con la segunda y en la línea entera con ninguna.
    $old = [['v', 'ROJO', 'v|ROJO|1'], ['v', 'AZUL', 'v|AZUL|1']];
    $new = [['v', 'AZUL', 'v|AZUL|2']];

    expect(LinePairing::pair($old, $new))->toBe([0 => 1]);
});
