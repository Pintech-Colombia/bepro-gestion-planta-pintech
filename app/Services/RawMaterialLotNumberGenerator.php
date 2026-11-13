<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryBatch;

final class RawMaterialLotNumberGenerator
{
    private const LOCK_KEY = 801341;

    public function __construct(
        private readonly YearlySequenceNumberGenerator $sequence,
    ) {}

    /**
     * El siguiente número de lote de materia prima (`MP-AAAA-NNNN`) del año en curso en la planta.
     *
     * Debe llamarse dentro de una transacción (ver `YearlySequenceNumberGenerator::next()`).
     */
    public function next(): string
    {
        return $this->sequence->next(InventoryBatch::query(), 'lot_number', 'MP', self::LOCK_KEY);
    }
}
