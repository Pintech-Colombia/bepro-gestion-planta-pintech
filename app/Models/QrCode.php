<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionOrderStatus;
use App\Support\QrHost;
use Database\Factories\QrCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $production_order_id
 * @property string $token
 * @property string $url
 * @property bool $is_active
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductionOrder $productionOrder
 * @property-read User $createdBy
 * @property-read Collection|QrDocument[] $documents
 */
#[Fillable([
    'product_id',
    'production_order_id',
    'token',
    'url',
    'is_active',
    'created_by',
])]
class QrCode extends Model
{
    /** @use HasFactory<QrCodeFactory> */
    use HasFactory;

    /** Largo del token: 36¹² ≈ 62 bits, inadivinable con el límite de peticiones de la página pública. */
    public const TOKEN_LENGTH = 12;

    /** Un solo tipo de letra: la búsqueda pasa el token a minúsculas, así que el QR podría pasar a mayúsculas sin romper lo impreso. */
    private const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Token nuevo, sin repetir. `random_int` es criptográficamente seguro y, a diferencia de pasar `Str::random()` a
     * minúsculas, no hace más probables unas letras que otras.
     */
    public static function newToken(): string
    {
        $last = strlen(self::TOKEN_ALPHABET) - 1;

        do {
            $token = '';

            for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
                $token .= self::TOKEN_ALPHABET[random_int(0, $last)];
            }
        } while (self::query()->where('token', $token)->exists());

        return $token;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active QR codes.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * La URL de la página pública del lote, la que lleva el QR impreso. Es el único sitio que la arma: estampita,
     * certificado, Códigos QR y la tarjeta de la OP la piden aquí.
     */
    public static function landingUrlFor(string $token): string
    {
        // Falla en un servidor sin QR_HOST antes de imprimir un QR que no abriría.
        QrHost::assertConfiguredForServer();

        return route('qr.public.show', ['token' => $token]);
    }

    public function landingUrl(): string
    {
        return self::landingUrlFor($this->token);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * El certificado es de un lote terminado: antes de completar la orden no hay nada que certificar.
     */
    public function acceptsCertificate(): bool
    {
        return $this->productionOrder?->status === ProductionOrderStatus::Completed;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(QrDocument::class, 'qr_code_id');
    }
}
