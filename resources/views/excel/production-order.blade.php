@php
    use App\Support\NumberFormat;

    /*
     * La misma hoja de planta del PDF (resources/views/pdf/production-order.blade.php), sección por sección, para que la
     * jefa la corrija y la imprima: carta vertical en una hoja (ProductionOrderExport). Cuadrícula de 12 columnas.
     */
    $pdfMaterials = $order['pdf_materials'] ?? ['mode' => 'steps', 'rows' => []];
    $pdfMode = $pdfMaterials['mode'] ?? 'steps';
    $pdfRows = $pdfMaterials['rows'] ?? [];
    $pdfTotals = $pdfMaterials['totals'] ?? ['kg' => '0', 'grams' => '0'];
    $packagingPlans = $order['packaging_plans'] ?? [];
    $isCancelled = ($order['status'] ?? '') === 'cancelled';
    $manufacturedOn = filled($order['manufactured_on'] ?? null) ? \Carbon\Carbon::parse($order['manufactured_on']) : null;
    $formulaNotes = $order['formula']['notes'] ?? null;
    $plantTime = fn (?string $value): string => filled($value)
        ? app(\App\Services\TimezoneService::class)->formatPlantDateTime($value, 'H:i')
        : '';

    $border = 'border: 1px solid #202532;';
    $section = $border.' background-color: #202532; color: #ffffff; font-weight: bold; text-align: center; vertical-align: middle;';
    $label = $border.' background-color: #e7e9ee; color: #202532; font-weight: bold; font-size: 9px; vertical-align: middle; word-wrap: break-word;';
    $value = $border.' color: #202532; vertical-align: middle;';
    $wrap = ' word-wrap: break-word; vertical-align: top;';
    $small = ' font-size: 9px; color: #606e80;';

    // Excel no ajusta el alto de una celda combinada: el renglón se agranda aquí según cuántas líneas ocupa su texto
    // (caracteres por línea de cada ancho, medidos con Arial 10 en la cuadrícula de ProductionOrderExport).
    $lines = fn (?string $text, int $perLine): int => max(1, (int) ceil(mb_strlen((string) $text) / $perLine));
    $rowHeight = fn (int $lines): int => max(16, $lines * 13 + 4);
@endphp
<table>
    {{-- ① CABECERA --}}
    <tr>
        <td colspan="3" rowspan="3" style="{{ $border }}">{{-- El logo va encima (WithDrawings). --}}</td>
        <td colspan="5" rowspan="3" style="{{ $border }} font-weight: bold; font-size: 18px; text-align: center; vertical-align: middle;">ORDEN DE PRODUCCIÓN</td>
        <td colspan="2" style="{{ $label }}">CÓDIGO</td>
        <td colspan="2" style="{{ $value }} font-weight: bold;">FPR-02</td>
    </tr>
    <tr>
        <td colspan="2" style="{{ $label }}">VERSIÓN</td>
        <td colspan="2" style="{{ $value }}">2.0</td>
    </tr>
    <tr>
        <td colspan="2" style="{{ $label }}">FECHA</td>
        {{-- La fecha de emisión del formato FPR-02 versión 2.0. --}}
        <td colspan="2" style="{{ $value }}">02/12/2020</td>
    </tr>

    {{-- Una orden cancelada no se produce: si alguien la imprime, que se note antes de leer nada más. --}}
    @if($isCancelled)
        <tr>
            <td colspan="12" style="border: 2px solid #b42318; color: #b42318; font-weight: bold; font-size: 12px; text-align: center;">ORDEN CANCELADA · NO USAR PARA PRODUCIR</td>
        </tr>
    @endif

    {{-- ② DATOS GENERALES DEL PRODUCTO A FABRICAR --}}
    <tr>
        <td colspan="12" style="{{ $section }}">DATOS GENERALES DEL PRODUCTO A FABRICAR</td>
    </tr>
    <tr>
        <td colspan="3" style="{{ $label }}">ORDEN DE PRODUCCIÓN N°</td>
        <td colspan="3" style="{{ $value }} font-weight: bold;">{{ $order['order_number'] }}</td>
        <td colspan="2" style="{{ $label }}">LOTE</td>
        <td colspan="4" style="{{ $value }}">{{ $order['lot_caption'] ?? '' }}</td>
    </tr>
    <tr height="{{ $rowHeight($lines($order['product_display_name'] ?? '', 22)) }}">
        <td colspan="3" style="{{ $label }}">NOMBRE DEL PRODUCTO</td>
        <td colspan="3" style="{{ $value }} font-weight: bold;{{ $wrap }}">{{ $order['product_display_name'] ?? 'N/A' }}</td>
        <td colspan="2" style="{{ $label }}">FECHA</td>
        {{-- La fecha de fabricación (creación de la OP, en fecha de planta). La planeada no se imprime. --}}
        <td style="{{ $value }} text-align: center;">{{ $manufacturedOn?->format('d') ?? '__' }}</td>
        <td style="{{ $value }} text-align: center;">{{ $manufacturedOn?->format('m') ?? '__' }}</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ $manufacturedOn?->format('Y') ?? '____' }}</td>
    </tr>
    <tr>
        <td colspan="3" style="{{ $label }}">CANTIDAD PROYECTADA</td>
        <td colspan="2" style="{{ $value }} font-weight: bold; text-align: center;">{{ NumberFormat::quantity($order['quantity']) }} gal</td>
        <td colspan="2" style="{{ $label }}">ENVASES A USAR</td>
        <td colspan="5" style="{{ $value }}{{ $wrap }}">
            @forelse($packagingPlans as $plan)
                {{ NumberFormat::units($plan['planned_units']) }} {{ $plan['product_variant']['presentation_label'] ?? 'uds' }}@if(!$loop->last), @endif
            @empty
                N/A
            @endforelse
        </td>
    </tr>
    <tr>
        <td colspan="3" style="{{ $label }}">CANTIDAD RESULTADO</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ ($order['actual_quantity'] ?? null) !== null ? NumberFormat::quantity($order['actual_quantity']).' gal' : '________ gal' }}</td>
        <td colspan="2" style="{{ $label }}">ENVASES USADOS</td>
        <td colspan="5" style="{{ $value }}{{ $wrap }}">
            {{-- Solo las presentaciones con unidades registradas, para que la coma no quede al final. --}}
            @foreach(collect($packagingPlans)->whereNotNull('actual_units') as $plan)
                {{ NumberFormat::units($plan['actual_units']) }} {{ $plan['product_variant']['presentation_label'] ?? 'uds' }}@if(!$loop->last), @endif
            @endforeach
        </td>
    </tr>

    {{-- ③ PESAJE Y TRANSFORMACIÓN MATERIA PRIMA --}}
    <tr>
        <td colspan="12" style="{{ $section }}">PESAJE Y TRANSFORMACIÓN MATERIA PRIMA</td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="2" style="{{ $label }}">INICIO AGITACIÓN</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ $plantTime($order['agitation_start_time'] ?? null) }}</td>
        <td colspan="2" style="{{ $label }}">FIN AGITACIÓN</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ $plantTime($order['agitation_end_time'] ?? null) }}</td>
        <td colspan="2" style="{{ $label }}">PESO POR GALÓN (kg/gal)</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ ($order['density_kg_per_gallon'] ?? null) !== null ? NumberFormat::quantity($order['density_kg_per_gallon']).' kg/gal' : '' }}</td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="2" style="{{ $label }}">INICIO ENVASADO</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ $plantTime($order['packaging_start_time'] ?? null) }}</td>
        <td colspan="2" style="{{ $label }}">FIN ENVASADO</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ $plantTime($order['packaging_end_time'] ?? null) }}</td>
        <td colspan="2" style="{{ $label }}">OBSERVACIONES PESAJE</td>
        <td colspan="2" style="{{ $value }}"></td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="2" style="{{ $label }}">INICIO EMPACADO</td>
        <td colspan="2" style="{{ $value }}"></td>
        <td colspan="2" style="{{ $label }}">FIN EMPACADO</td>
        <td colspan="2" style="{{ $value }}"></td>
        <td colspan="2" style="{{ $label }}">DERRAMES EN ENVASADO</td>
        <td colspan="2" style="{{ $value }} word-wrap: break-word;">
            @if(($order['spillage_quantity'] ?? 0) > 0)
                SÍ — {{ NumberFormat::quantity($order['spillage_quantity']) }} kg
            @elseif(in_array($order['status'] ?? '', ['pending_review', 'completed'], true))
                {{-- El derrame no admite vacío (0 por defecto): desde la revisión, 0 es la respuesta del operario. --}}
                NO
            @else
                SÍ ☐ NO ☐ Cant.: ____ kg
            @endif
        </td>
    </tr>

    {{-- ④ INSTRUCCIONES PARA PRODUCCIÓN (notas de la fórmula) --}}
    <tr>
        <td colspan="12" style="{{ $section }}">INSTRUCCIONES PARA PRODUCCIÓN</td>
    </tr>
    <tr height="{{ $rowHeight(max(2, $lines($formulaNotes, 85))) }}">
        <td colspan="3" style="{{ $label }}">INDICACIONES DE LA FÓRMULA:</td>
        <td colspan="9" style="{{ $value }}{{ $wrap }}">{{ filled($formulaNotes) ? $formulaNotes : '—' }}</td>
    </tr>

    {{-- ⑤ MATERIA PRIMA Y CANTIDADES: por paso mientras se produce; agrupada por MP con la orden completada. --}}
    <tr>
        <td colspan="12" style="{{ $section }}">MATERIA PRIMA Y CANTIDADES</td>
    </tr>
    <tr>
        @if($pdfMode !== 'consolidated')
            <td style="{{ $label }} text-align: center;">#</td>
            <td colspan="2" style="{{ $label }} text-align: center;">COD</td>
        @else
            <td colspan="3" style="{{ $label }} text-align: center;">COD</td>
        @endif
        <td style="{{ $label }} text-align: center;">CANT. KG</td>
        <td style="{{ $label }} text-align: center;">CANT. G</td>
        <td colspan="5" style="{{ $label }} text-align: center;">OBSERVACIONES</td>
        <td colspan="2" style="{{ $label }} text-align: center;">AGREGADO TOTAL</td>
    </tr>
    @foreach($pdfRows as $row)
        @php
            $isProduct = ($row['kind'] ?? 'material') === 'product';
            $kg = $row['display_kg'] ?? '0';
            $grams = $row['display_grams'] ?? '0';
            $codeLines = $lines($row['raw_material_code'].($isProduct ? ' (PT)' : ''), $pdfMode === 'consolidated' ? 22 : 14);
            $observationLines = collect($row['observations'] ?? [])->sum(fn (string $observation): int => $lines($observation, 50));
        @endphp
        {{-- Dos líneas como mínimo: en OBSERVACIONES la planta anota a mano lo que va echando. --}}
        <tr height="{{ $rowHeight(max(2, $codeLines, $observationLines)) }}">
            @if($pdfMode !== 'consolidated')
                <td style="{{ $value }} text-align: center;">{{ $row['step_order'] ?? '' }}</td>
                <td colspan="2" style="{{ $value }} font-weight: bold; text-align: center;{{ $wrap }}">{{ $row['raw_material_code'] }}{{ $isProduct ? ' (PT)' : '' }}</td>
            @else
                <td colspan="3" style="{{ $value }} font-weight: bold; text-align: center;{{ $wrap }}">{{ $row['raw_material_code'] }}{{ $isProduct ? ' (PT)' : '' }}</td>
            @endif
            <td style="{{ $value }} text-align: center;">{{ $kg > 0 ? NumberFormat::quantity($kg) : '' }}</td>
            <td style="{{ $value }} text-align: center;">{{ $grams > 0 ? NumberFormat::units($grams) : '' }}</td>
            {{-- De dónde sale el producto, la raya de lo que quedó y lo registrado (BuildProductionOrderPdfMaterialsAction). --}}
            <td colspan="5" style="{{ $value }}{{ $wrap }}{{ $small }}">
                @foreach($row['observations'] ?? [] as $observation)
                    {{ $observation }}@if(!$loop->last)<br>@endif
                @endforeach
            </td>
            <td colspan="2" style="{{ $value }} text-align: center;">{{ isset($row['actual_quantity']) ? NumberFormat::quantity($row['actual_quantity']) : '' }}</td>
        </tr>
    @endforeach
    <tr>
        <td colspan="3" style="{{ $label }} text-align: center;">TOTAL</td>
        <td style="{{ $value }} font-weight: bold; text-align: center;">{{ NumberFormat::quantity($pdfTotals['kg'] ?? 0) }}</td>
        <td style="{{ $value }} font-weight: bold; text-align: center;">{{ (float) ($pdfTotals['grams'] ?? 0) > 0 ? NumberFormat::units($pdfTotals['grams']) : '' }}</td>
        <td colspan="5" style="{{ $value }}"></td>
        <td colspan="2" style="{{ $value }} font-weight: bold; text-align: center;">{{ (float) ($pdfTotals['actual_quantity'] ?? 0) > 0 ? NumberFormat::quantity($pdfTotals['actual_quantity']) : '' }}</td>
    </tr>

    {{-- ⑤.1 AJUSTES DE LÍNEA (MPs fuera de fórmula) --}}
    @if(count($order['line_adjustments'] ?? []) > 0)
        <tr>
            <td colspan="12" style="{{ $section }}">AJUSTES DE LÍNEA (MPs ADICIONALES)</td>
        </tr>
        <tr>
            <td colspan="3" style="{{ $label }} text-align: center;">MATERIA PRIMA</td>
            <td colspan="2" style="{{ $label }} text-align: center;">CANTIDAD</td>
            <td colspan="4" style="{{ $label }} text-align: center;">MOTIVO</td>
            <td colspan="3" style="{{ $label }} text-align: center;">OBSERVACIONES</td>
        </tr>
        @foreach($order['line_adjustments'] as $adjustment)
            <tr>
                <td colspan="3" style="{{ $value }} text-align: center;">{{ $adjustment['raw_material']['code'] ?? 'N/A' }}</td>
                <td colspan="2" style="{{ $value }} text-align: center;">{{ NumberFormat::quantity($adjustment['quantity']) }}</td>
                <td colspan="4" style="{{ $value }}{{ $wrap }}">{{ $adjustment['reason'] }}</td>
                <td colspan="3" style="{{ $value }}{{ $wrap }}">{{ $adjustment['notes'] ?? '' }}</td>
            </tr>
        @endforeach
    @endif

    {{-- ⑥ NOMBRE RESPONSABLE --}}
    <tr>
        <td colspan="3" style="{{ $label }}">NOMBRE RESPONSABLE</td>
        <td colspan="9" style="{{ $value }}">{{ $order['responsible_name'] ?? '' }}</td>
    </tr>

    {{-- ⑦ RESULTADOS DE CALIDAD (con el rango esperado del producto) --}}
    <tr>
        <td colspan="12" style="{{ $section }}">RESULTADOS DE CALIDAD</td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="3" style="{{ $label }}{{ $wrap }}">MOLIENDA (HG)@if(filled($order['expected_ranges']['fineness'] ?? null))<br>{{ $order['expected_ranges']['fineness'] }}@endif</td>
        <td style="{{ $value }} text-align: center;">{{ ($order['grinding_hg'] ?? null) !== null ? NumberFormat::measurement($order['grinding_hg'], 1) : '' }}</td>
        <td colspan="3" style="{{ $label }}">TIEMPO TOTAL MOLIENDA</td>
        <td style="{{ $value }}"></td>
        <td colspan="2" style="{{ $label }}{{ $wrap }}">SÓLIDOS (%)@if(filled($order['expected_ranges']['solids'] ?? null))<br>{{ $order['expected_ranges']['solids'] }}@endif</td>
        <td colspan="2" style="{{ $value }} text-align: center;">{{ ($order['quality_solids'] ?? null) !== null ? NumberFormat::measurement($order['quality_solids'], 1) : '' }}</td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="3" style="{{ $label }}{{ $wrap }}">VISCOSIDAD A 25 °C (KU)@if(filled($order['expected_ranges']['viscosity'] ?? null))<br>{{ $order['expected_ranges']['viscosity'] }}@endif</td>
        <td style="{{ $value }} text-align: center;">{{ ($order['viscosity_ku'] ?? null) !== null ? NumberFormat::measurement($order['viscosity_ku'], 1) : '' }}</td>
        <td colspan="3" style="{{ $label }}">AJUSTES PARA LA VISCOSIDAD</td>
        <td colspan="5" style="{{ $value }}">SÍ ☐ NO ☐ Ajustes realizados:</td>
    </tr>
    <tr height="{{ $rowHeight(2) }}">
        <td colspan="3" style="{{ $label }}">CORRIDO DE PODER CUBRIENTE</td>
        <td style="{{ $value }}"></td>
        <td colspan="3" style="{{ $label }}">AJUSTES PARA EL PODER CUBRIENTE</td>
        <td colspan="5" style="{{ $value }}">SÍ ☐ NO ☐ Ajustes realizados:</td>
    </tr>

    {{-- ⑧ OBSERVACIONES FINALES (notas de la orden + renglones para escribir) --}}
    <tr>
        <td colspan="12" style="{{ $section }}">OBSERVACIONES FINALES</td>
    </tr>
    @if(filled($order['notes'] ?? null))
        <tr>
            <td colspan="12" style="{{ $value }}{{ $wrap }}">Notas de la orden: {{ $order['notes'] }}</td>
        </tr>
    @endif
    @for($i = 0; $i < 3; $i++)
        <tr height="20">
            <td colspan="12" style="{{ $value }}"></td>
        </tr>
    @endfor
</table>
