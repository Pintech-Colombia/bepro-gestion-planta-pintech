<?php

declare(strict_types=1);

use App\Services\VariantSalesPriceService;

beforeEach(function () {
    $this->service = app(VariantSalesPriceService::class);
});

test('it calculates margin from base and sales price', function () {
    expect($this->service->resolveMarginFromSalesPrice('92', '115'))->toBe('20.00');
    expect($this->service->resolveMarginFromSalesPrice(92.0, 115.0))->toBe('20.00');
});

test('it returns zero margin when prices are equal', function () {
    expect($this->service->resolveMarginFromSalesPrice('100', '100'))->toBe('0.00');
});

test('it normalizes floats without scientific notation', function () {
    expect($this->service->resolveMarginFromSalesPrice(0.0001, 0.0002))->toBe('50.00');
});

test('applySalesMargin and resolveMarginFromSalesPrice match on exact whole-peso prices', function () {
    // 92 / 0.8 = 115 exacto (sin redondeo al peso): la inversa recupera exactamente el 20 %.
    $price = $this->service->applySalesMargin('92', 20.0);
    $margin = $this->service->resolveMarginFromSalesPrice('92', $price);

    expect($price)->toBe('115')
        ->and((float) $margin)->toBe(20.0);
});

test('applySalesMargin treats null and empty string as zero margin', function () {
    expect($this->service->applySalesMargin('100.4000', null))->toBe('100')
        ->and($this->service->applySalesMargin('100.6000', ''))->toBe('101');
});

test('resolveForProduct normalizes float current_price safely', function () {
    // This test ensures float-to-string normalization does not break existing behavior.
    // Using a float that could otherwise lose precision or become scientific notation.
    $price = $this->service->applySalesMargin(92.0, 20.0);

    expect($price)->toBe('115');
});

test('applySalesMargin rounds list price to whole pesos half-up', function () {
    // 92 / 0.75 = 122.6666... → 123 (redondea hacia arriba)
    expect($this->service->applySalesMargin('92.0000', '25.00'))->toBe('123');
    // 460 / 0.75 = 613.3333... → 613 (redondea hacia abajo)
    expect($this->service->applySalesMargin('460.0000', '25.00'))->toBe('613');
    // 100.5 / 1 = 100.5 → 101 (mitad hacia arriba)
    expect($this->service->applySalesMargin('100.5000', '0.00'))->toBe('101');
    // 1766902.5 / (1 - 0.2932) = 2499862.0543... → 2499862
    expect($this->service->applySalesMargin('1766902.5000', '29.32'))->toBe('2499862');
});
