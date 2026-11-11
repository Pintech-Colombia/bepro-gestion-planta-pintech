<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaintDevelopmentRequestStatus;
use App\Enums\Permission;
use App\Models\Concerns\HasAuditDescription;
use App\Support\NumberFormat;
use Database\Factories\PaintDevelopmentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $request_number
 * @property PaintDevelopmentRequestStatus $status
 * @property string $client_name
 * @property string $project_name
 * @property string $responsible
 * @property string $city
 * @property Carbon $sample_due_date
 * @property string|null $current_product
 * @property array<string, mixed>|null $context_payload
 * @property array<string, mixed>|null $performance_payload
 * @property array<string, mixed>|null $application_payload
 * @property array<string, mixed>|null $specifications_payload
 * @property int $schema_version
 * @property string|null $review_notes
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $creator
 * @property-read User|null $reviewer
 */
#[Fillable([
    'status',
    'client_name',
    'project_name',
    'responsible',
    'city',
    'sample_due_date',
    'current_product',
    'context_payload',
    'performance_payload',
    'application_payload',
    'specifications_payload',
    'schema_version',
    'review_notes',
    'reviewed_by',
    'reviewed_at',
    'created_by',
])]
class PaintDevelopmentRequest extends Model
{
    /**
     * Números técnicos de la solicitud, por bloque del JSON: los mismos de `numeric-payload.ts` y de las reglas de
     * validación (lo comprueba `PaintDevelopmentRequestPdfValuesTest`).
     */
    public const NUMERIC_PAYLOAD_FIELDS = [
        'context_payload' => ['temp_min', 'temp_max'],
        'application_payload' => [
            'temp_aplicacion',
            'humedad_aplicacion',
            'dft_min',
            'dft_max',
            'manos',
            'pot_life',
            'solidos_vol',
            'voc',
        ],
        'performance_payload' => ['adherencia_obj', 'camara_obj', 'delta_e'],
    ];

    /**
     * Etiquetas legibles y orden de presentación para el PDF, alineadas con los pasos del formulario y las reglas.
     *
     * @var array<string, array<string, string>>
     */
    public const PAYLOAD_FIELD_LABELS = [
        'context_payload' => [
            'sustrato' => 'Sustrato principal',
            'otro_sustrato' => 'Especificación del sustrato',
            'estado_superficie' => 'Estado de la superficie',
            'pintura_existente' => '¿Pintura existente?',
            'sistema_existente' => 'Sistema de pintura actual',
            'preparacion' => 'Preparación superficial',
            'exposicion' => 'Tipo de exposición',
            'agua_humedad' => 'Contacto con agua o humedad',
            'corrosividad' => 'Categoría de corrosividad',
            'temp_min' => 'Temperatura mín. servicio (°C)',
            'temp_max' => 'Temperatura máx. servicio (°C)',
            'ciclos_termicos' => 'Ciclos térmicos',
            'quimicos' => 'Exposición a químicos / contaminantes',
        ],
        'performance_payload' => [
            'funcion' => 'Función principal',
            'vida_util' => 'Vida útil esperada',
            'prioridad' => 'Prioridad técnica',
            'pruebas' => 'Pruebas o evidencias requeridas',
            'adherencia_obj' => 'Adherencia objetivo (psi)',
            'camara_obj' => 'Cámara salina objetivo (h)',
            'criterios_tecnicos' => 'Otros criterios técnicos',
            'indispensable' => 'Características indispensables',
            'negociable' => 'Características negociables',
            'color' => 'Color o referencia',
            'brillo' => 'Nivel de brillo',
            'textura' => 'Textura',
            'cubricion' => 'Cubrición esperada',
            'delta_e' => 'Tolerancia máxima Delta E',
            'retencion' => 'Retención de color y brillo',
            'acabado_ref' => 'Referencia visual / acabado',
        ],
        'application_payload' => [
            'metodo' => 'Método de aplicación',
            'temp_aplicacion' => 'Temperatura de aplicación (°C)',
            'humedad_aplicacion' => 'Humedad relativa (% HR)',
            'geometria' => 'Geometría o tipo de pieza',
            'dft_min' => 'Espesor seco mín. por mano (mils)',
            'dft_max' => 'Espesor seco máx. por mano (mils)',
            'manos' => 'Número previsto de manos',
            'repinte' => 'Tiempo entre manos',
            'servicio' => 'Puesta en servicio',
            'antidescuelgue' => 'Resistencia al descuelgue',
            'equipo_detalle' => 'Equipo, boquilla o restricciones',
            'vehiculo' => 'Base requerida',
            'tecnologia' => 'Tecnología preferida',
            'otra_tecnologia' => 'Especificación de tecnología',
            'componentes' => 'Número de componentes',
            'relacion_mezcla' => 'Relación de mezcla',
            'pot_life' => 'Pot life mínimo (min)',
            'solidos_vol' => 'Sólidos por volumen (%)',
            'ajustador' => '¿Se permite ajustador?',
            'voc' => 'VOC máximo permitido (g/L)',
            'restricciones' => 'Restricciones especiales',
            'sistema_capas' => 'Sistema completo previsto',
        ],
        'specifications_payload' => [
            'viscosidad_metodo' => 'Viscosidad y método',
            'densidad' => 'Densidad objetivo',
            'solidos_peso' => 'Sólidos por peso objetivo',
            'finura' => 'Finura de molienda',
            'secados' => 'Tiempos de secado objetivo',
            'estabilidad' => 'Estabilidad en envase',
            'presentacion' => 'Presentaciones requeridas',
            'consumo' => 'Consumo estimado',
            'frecuencia' => 'Frecuencia de compra',
            'almacenamiento' => 'Almacenamiento y transporte',
            'kit_ab' => 'Configuración del kit A/B',
            'competidor' => 'Producto competidor',
            'meta_competidor' => 'Objetivo frente a la referencia',
            'costo_objetivo' => 'Costo o precio objetivo',
            'cantidad_prueba' => 'Cantidad para muestra / prueba',
            'aprobador' => 'Quién aprueba el desarrollo',
            'forma_aprobacion' => 'Forma de aprobación',
            'criterios_aprobacion' => 'Criterios exactos de aprobación',
            'observaciones' => 'Observaciones adicionales',
        ],
    ];

    /**
     * Campos condicionales que solo aplican según otra respuesta (espejo de `conditional-fields.ts`).
     *
     * @var array<string, array{source: string, value: string}>
     */
    public const CONDITIONAL_PAYLOAD_FIELDS = [
        'otro_sustrato' => ['source' => 'sustrato', 'value' => 'Otro'],
        'sistema_existente' => ['source' => 'pintura_existente', 'value' => 'Sí'],
        'otra_tecnologia' => ['source' => 'tecnologia', 'value' => 'Otra'],
    ];

    /**
     * Campos descriptivos o de selección múltiple que ocupan el ancho completo de la fila en el PDF.
     *
     * @var array<string, list<string>>
     */
    public const FULL_WIDTH_PAYLOAD_FIELDS = [
        'context_payload' => ['sistema_existente', 'preparacion', 'exposicion', 'quimicos'],
        'performance_payload' => ['funcion', 'pruebas', 'criterios_tecnicos', 'indispensable', 'negociable', 'acabado_ref'],
        'application_payload' => ['metodo', 'equipo_detalle', 'otra_tecnologia', 'restricciones', 'sistema_capas'],
        'specifications_payload' => ['secados', 'estabilidad', 'presentacion', 'almacenamiento', 'kit_ab', 'criterios_aprobacion', 'observaciones'],
    ];

    /** Una respuesta como se lee en el PDF: el 0 es un dato, y un texto en un campo numérico sigue como texto. */
    public static function displayPayloadValue(string $payload, string $field, mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        if (blank($value)) {
            return NumberFormat::DEFAULT_EMPTY;
        }

        return in_array($field, self::NUMERIC_PAYLOAD_FIELDS[$payload] ?? [], true) && is_numeric($value)
            ? NumberFormat::quantity($value)
            : (string) $value;
    }

    public static function displayPayloadLabel(string $payload, string $field): string
    {
        return self::PAYLOAD_FIELD_LABELS[$payload][$field]
            ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * @param  array<string, mixed>  $payloadData
     */
    public static function payloadFieldApplies(string $field, array $payloadData): bool
    {
        $condition = self::CONDITIONAL_PAYLOAD_FIELDS[$field] ?? null;

        return $condition === null || ($payloadData[$condition['source']] ?? null) === $condition['value'];
    }

    public static function isFullWidthPayloadField(string $payload, string $field): bool
    {
        return in_array($field, self::FULL_WIDTH_PAYLOAD_FIELDS[$payload] ?? [], true);
    }

    /**
     * Organiza un bloque del payload en filas para una tabla de 2 columnas (`th | td | th | td`).
     *
     * @param  array<string, mixed>  $payloadData
     * @return list<list<array{key: string, label: string, value: string, full_width: bool}>>
     */
    public static function pdfPayloadRows(string $payload, array $payloadData): array
    {
        $definedKeys = array_keys(self::PAYLOAD_FIELD_LABELS[$payload] ?? []);
        $extraKeys = array_values(array_diff(array_keys($payloadData), $definedKeys));
        $orderedKeys = [...$definedKeys, ...$extraKeys];

        $items = [];

        foreach ($orderedKeys as $field) {
            if (! array_key_exists($field, $payloadData)) {
                continue;
            }

            if (! self::payloadFieldApplies($field, $payloadData)) {
                continue;
            }

            $items[] = [
                'key' => $field,
                'label' => self::displayPayloadLabel($payload, $field),
                'value' => self::displayPayloadValue($payload, $field, $payloadData[$field]),
                'full_width' => self::isFullWidthPayloadField($payload, $field),
            ];
        }

        $rows = [];
        $pendingPairItem = null;

        foreach ($items as $item) {
            if ($item['full_width']) {
                if ($pendingPairItem !== null) {
                    $rows[] = [$pendingPairItem];
                    $pendingPairItem = null;
                }

                $rows[] = [$item];

                continue;
            }

            if ($pendingPairItem === null) {
                $pendingPairItem = $item;

                continue;
            }

            $rows[] = [$pendingPairItem, $item];
            $pendingPairItem = null;
        }

        if ($pendingPairItem !== null) {
            $rows[] = [$pendingPairItem];
        }

        return $rows;
    }

    /** @use HasFactory<PaintDevelopmentRequestFactory> */
    use HasAuditDescription, HasFactory, LogsActivity;

    protected string $auditLabel = 'Solicitud de Desarrollo';

    protected string $auditIdentifierAttribute = 'request_number';

    protected bool $auditFeminine = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('desarrollo_pinturas')
            ->setDescriptionForEvent(fn (string $eventName) => $this->getAuditDescription($eventName))
            ->logOnly([
                'request_number',
                'status',
                'client_name',
                'project_name',
                'responsible',
                'city',
                'sample_due_date',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'request_number' => 'integer',
            'status' => PaintDevelopmentRequestStatus::class,
            'sample_due_date' => 'date:Y-m-d',
            'context_payload' => 'array',
            'performance_payload' => 'array',
            'application_payload' => 'array',
            'specifications_payload' => 'array',
            'schema_version' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Registros con dueño (docs/MATRIZ_RBAC.md §1): view_all ve todos; view_own solo los propios.
     * Sin usuario autenticado no se ve nada (fail-safe): este scope solo se llama detrás de 'auth',
     * pero no debe asumir eso silenciosamente si algún día se reutiliza desde un job o comando.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            // Fail-safe: sin usuario, no se devuelve ningún registro (nunca "todo").
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(Permission::PaintDevelopmentRequestsViewAll->value)) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
