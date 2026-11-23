<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Orden {{ $order['order_number'] }}</title>
    @php
        use App\Support\NumberFormat;

        $pdfMaterials = $order['pdf_materials'] ?? ['mode' => 'steps', 'rows' => []];
        $pdfMode = $pdfMaterials['mode'] ?? 'steps';
        $pdfRows = $pdfMaterials['rows'] ?? [];
        $pdfTotals = $pdfMaterials['totals'] ?? ['kg' => '0', 'grams' => '0'];

        $lineAdjustmentsCount = count($order['line_adjustments'] ?? []);
        $isCancelled = ($order['status'] ?? '') === 'cancelled';
        // Las observaciones de cada producto (fuentes, rayas, lo que quedó) ocupan renglones de más dentro de su fila.
        $productExtraLines = collect($pdfRows)
            ->where('kind', 'product')
            ->sum(fn (array $row): int => max(count($row['observations'] ?? []) - 1, 0));
        $effectiveLoad = count($pdfRows)
            + ($lineAdjustmentsCount > 0 ? $lineAdjustmentsCount + 2 : 0)
            + $productExtraLines
            + ($isCancelled ? 1 : 0);

        // Escala adaptable para que la OP quepa siempre en 1 sola hoja carta sin recortar materias primas.
        $compactPass = (bool) ($compactPass ?? false);
        if ($compactPass) {
            $effectiveLoad += 6;
        }

        if ($effectiveLoad <= 12) {
            $mpRowPaddingY = '4.4pt';
            $sectionGap = '4pt';
            $finalNotesLines = 2;
        } elseif ($effectiveLoad <= 16) {
            $mpRowPaddingY = '3.1pt';
            $sectionGap = '3pt';
            $finalNotesLines = 2;
        } elseif ($effectiveLoad <= 20) {
            $mpRowPaddingY = '2.2pt';
            $sectionGap = '2.4pt';
            $finalNotesLines = 1;
        } elseif ($effectiveLoad <= 23) {
            $mpRowPaddingY = '1.5pt';
            $sectionGap = '1.8pt';
            $finalNotesLines = 1;
        } else {
            $mpRowPaddingY = '1.1pt';
            $sectionGap = '1.5pt';
            $finalNotesLines = 1;
        }

        // «Esperado: 95,0 – 105,0 KU»: lo arma BuildProductionOrderExportDataAction, igual para el Excel.
        $finenessRange = $order['expected_ranges']['fineness'] ?? null;
        $viscosityRange = $order['expected_ranges']['viscosity'] ?? null;
        $solidsRange = $order['expected_ranges']['solids'] ?? null;

        $formulaNotes = $order['formula']['notes'] ?? null;
    @endphp
    <style>
        /*
         * No usar `* { margin: 0 }`: en DomPDF alcanza a `@page` y anula los márgenes de impresión.
         */
        @page {
            margin: 14pt 20pt 22pt 20pt;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.8pt;
            color: #202532;
            line-height: 1.18;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        tr {
            page-break-inside: avoid;
        }

        td,
        th {
            border: 0.75pt solid #b8bec8;
            padding: 2pt 4pt;
            vertical-align: middle;
            text-align: left;
        }

        .section-table {
            margin-top: {{ $sectionGap }};
        }

        /* ── Cabecera estilo formato técnico ISO ── */
        .header-table td,
        .header-table th {
            border: 0.75pt solid #b8bec8;
            vertical-align: middle;
        }

        .logo-cell {
            width: 22%;
            text-align: center;
            padding: 2.5pt;
        }

        .logo-img {
            max-height: 28pt;
            max-width: 105pt;
        }

        .title-cell {
            width: 48%;
            text-align: center;
            font-size: 11.5pt;
            font-weight: bold;
            color: #202532;
            letter-spacing: 0.4pt;
        }

        .meta-label {
            width: 12%;
            background-color: #e7e9ee;
            color: #202532;
            font-weight: bold;
            font-size: 6.6pt;
            text-transform: uppercase;
            padding: 1.8pt 4pt;
        }

        .meta-value {
            width: 18%;
            font-size: 7.4pt;
            color: #202532;
            padding: 1.8pt 4pt;
        }

        /* ── Secciones y celdas ── */
        .section-header {
            background-color: #202532;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
            font-size: 7.4pt;
            padding: 2pt 5pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            border-color: #202532;
        }

        .cancelled-banner {
            border: 1.5pt solid #b42318;
            color: #b42318;
            font-weight: bold;
            font-size: 10pt;
            text-align: center;
            letter-spacing: 0.5pt;
            padding: 3pt 5pt;
        }

        .label {
            font-weight: bold;
            background-color: #e7e9ee;
            color: #202532;
            font-size: 6.6pt;
            text-transform: uppercase;
        }

        .value {
            font-size: 7.8pt;
            color: #202532;
        }

        .compact-row td {
            padding: 1.5pt 4pt;
            font-size: 7.2pt;
        }

        .compact-row td.label {
            font-size: 6.5pt;
        }

        .mp-row td {
            padding: {{ $mpRowPaddingY }} 4pt;
            font-size: 7.5pt;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: bold;
        }

        .pt-name {
            font-size: 7.5pt;
        }

        .small-text {
            font-size: 6.6pt;
            color: #606e80;
            font-weight: normal;
            text-transform: none;
        }

        .expected-range {
            display: block;
            font-size: 6.4pt;
            color: #606e80;
            font-weight: normal;
            text-transform: none;
            margin-top: 0.8pt;
        }

        .check-box {
            display: inline-block;
            width: 6.5pt;
            height: 6.5pt;
            border: 0.75pt solid #202532;
            vertical-align: middle;
            margin-left: 1.5pt;
            margin-right: 4pt;
        }

        .instructions-text {
            font-size: 7.6pt;
            padding: 3pt 5pt;
        }

        .adjustment-cell {
            padding: 4.5pt 6pt;
            text-align: left;
        }

        .write-line {
            height: 13pt;
            border-bottom: 0.5pt dashed #b8bec8;
        }

        .footer {
            position: fixed;
            bottom: -16pt;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 6.8pt;
            color: #606e80;
            border-top: 0.5pt solid #d0d4dc;
            padding-top: 2pt;
        }
    </style>
</head>

<body>
    {{-- PIE FIJO (va primero en <body> para que DomPDF lo registre desde la hoja 1) --}}
    <div class="footer">
        Orden {{ $order['order_number'] }} &nbsp;·&nbsp; Generado por Pintech OS — {{ $generatedAt }}
    </div>

    {{-- ① CABECERA --}}
    <table class="header-table">
        <tr>
            <td rowspan="3" class="logo-cell">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Pintech">
                @else
                    <strong style="font-size: 13pt;">PINTECH</strong>
                @endif
            </td>
            <td rowspan="3" class="title-cell">
                ORDEN DE PRODUCCIÓN
            </td>
            <th class="meta-label">CÓDIGO</th>
            <td class="meta-value"><strong>FPR-02</strong></td>
        </tr>
        <tr>
            <th class="meta-label">VERSIÓN</th>
            <td class="meta-value">2.0</td>
        </tr>
        <tr>
            <th class="meta-label">FECHA</th>
            {{-- La fecha de emisión del formato FPR-02 versión 2.0; la de generación va en el pie. --}}
            <td class="meta-value">02/12/2020</td>
        </tr>
    </table>

    {{-- Una orden cancelada no se produce: si alguien la imprime, que se note antes de leer nada más. --}}
    @if($isCancelled)
        <table class="section-table">
            <tr>
                <td class="cancelled-banner">ORDEN CANCELADA · NO USAR PARA PRODUCIR</td>
            </tr>
        </table>
    @endif

    {{-- ② DATOS GENERALES DEL PRODUCTO A FABRICAR --}}
    <table class="section-table">
        <tr>
            <td colspan="9" class="section-header">DATOS GENERALES DEL PRODUCTO A FABRICAR</td>
        </tr>
        <tr>
            <td colspan="2" class="label" style="width: 24%;">ORDEN DE PRODUCCIÓN N°</td>
            <td colspan="3" class="value" style="width: 32%;">
                <strong>{{ $order['order_number'] }}</strong>
            </td>
            <td class="label" style="width: 12%;">LOTE</td>
            <td colspan="3" class="value" style="width: 32%;">
                {{ $order['lot_caption'] }}
            </td>
        </tr>
        <tr>
            <td colspan="2" class="label">NOMBRE DEL PRODUCTO</td>
            <td colspan="3" class="value">
                <strong>{{ $order['product_display_name'] ?? 'N/A' }}</strong>
            </td>
            <td class="label">FECHA</td>
            @php
                // La fecha de fabricación (creación de la OP, en fecha de planta). La planeada no se imprime.
                $manufacturedOn = filled($order['manufactured_on'] ?? null) ? \Carbon\Carbon::parse($order['manufactured_on']) : null;
            @endphp
            <td class="value text-center" style="width: 10%;">{{ $manufacturedOn?->format('d') ?? '__' }}</td>
            <td class="value text-center" style="width: 10%;">{{ $manufacturedOn?->format('m') ?? '__' }}</td>
            <td class="value text-center" style="width: 12%;">{{ $manufacturedOn?->format('Y') ?? '__' }}</td>
        </tr>
        <tr>
            <td colspan="2" class="label">CANTIDAD PROYECTADA</td>
            <td class="value text-center" style="width: 14%;">
                <strong>{{ NumberFormat::quantity($order['quantity']) }} gal</strong>
            </td>
            <td class="label" style="width: 14%;">ENVASES A USAR</td>
            <td colspan="5" class="value">
                @if(count($order['packaging_plans']) > 0)
                    @foreach($order['packaging_plans'] as $plan)
                        {{ NumberFormat::units($plan['planned_units']) }}
                        {{ $plan['product_variant']['presentation_label'] ?? 'uds' }}@if(!$loop->last), @endif
                    @endforeach
                @else
                    N/A
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2" class="label">CANTIDAD RESULTADO</td>
            <td class="value text-center">
                {{ $order['actual_quantity'] !== null ? NumberFormat::quantity($order['actual_quantity']).' gal' : '________ gal' }}
            </td>
            <td class="label">ENVASES USADOS</td>
            <td colspan="5" class="value">
                @if(count($order['packaging_plans']) > 0)
                    {{-- Solo las presentaciones con unidades registradas, para que la coma no quede al final. --}}
                    @foreach(collect($order['packaging_plans'])->whereNotNull('actual_units') as $plan)
                        {{ NumberFormat::units($plan['actual_units']) }}
                        {{ $plan['product_variant']['presentation_label'] ?? 'uds' }}@if(!$loop->last), @endif
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    {{-- ③ PESAJE Y TRANSFORMACIÓN MATERIA PRIMA (sección compacta) --}}
    <table class="section-table">
        <tr>
            <td colspan="6" class="section-header">PESAJE Y TRANSFORMACIÓN MATERIA PRIMA</td>
        </tr>
        <tr class="compact-row">
            <td class="label" style="width: 16%;">INICIO AGITACIÓN</td>
            <td class="value text-center" style="width: 11%;">
                {{ filled($order['agitation_start_time'] ?? null) ? app(\App\Services\TimezoneService::class)->formatPlantDateTime($order['agitation_start_time'], 'H:i') : '' }}
            </td>
            <td class="label" style="width: 16%;">FIN AGITACIÓN</td>
            <td class="value text-center" style="width: 11%;">
                {{ filled($order['agitation_end_time'] ?? null) ? app(\App\Services\TimezoneService::class)->formatPlantDateTime($order['agitation_end_time'], 'H:i') : '' }}
            </td>
            <td class="label" style="width: 19%;">PESO POR GALÓN (kg/gal)</td>
            <td class="value text-center" style="width: 27%;">
                {{ ($order['density_kg_per_gallon'] ?? null) !== null ? NumberFormat::quantity($order['density_kg_per_gallon']).' kg/gal' : '' }}
            </td>
        </tr>
        <tr class="compact-row">
            <td class="label">INICIO ENVASADO</td>
            <td class="value text-center">
                {{ filled($order['packaging_start_time'] ?? null) ? app(\App\Services\TimezoneService::class)->formatPlantDateTime($order['packaging_start_time'], 'H:i') : '' }}
            </td>
            <td class="label">FIN ENVASADO</td>
            <td class="value text-center">
                {{ filled($order['packaging_end_time'] ?? null) ? app(\App\Services\TimezoneService::class)->formatPlantDateTime($order['packaging_end_time'], 'H:i') : '' }}
            </td>
            <td class="label">OBSERVACIONES PESAJE</td>
            <td class="value"></td>
        </tr>
        <tr class="compact-row">
            <td class="label">INICIO EMPACADO</td>
            <td class="value text-center"></td>
            <td class="label">FIN EMPACADO</td>
            <td class="value text-center"></td>
            <td class="label">DERRAMES EN ENVASADO</td>
            <td class="value">
                @if(($order['spillage_quantity'] ?? 0) > 0)
                    SÍ — {{ NumberFormat::quantity($order['spillage_quantity']) }} kg
                @elseif(in_array($order['status'] ?? '', ['pending_review', 'completed'], true))
                    {{-- El derrame no admite vacío (0 por defecto): desde la revisión, 0 es la respuesta del operario. --}}
                    NO
                @else
                    SÍ <span class="check-box">&nbsp;</span> NO <span class="check-box">&nbsp;</span>
                    <span class="small-text">Cant.: ______ kg</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- ④ INSTRUCCIONES PARA PRODUCCIÓN (notas de la fórmula) --}}
    <table class="section-table">
        <tr>
            <td colspan="6" class="section-header">INSTRUCCIONES PARA PRODUCCIÓN</td>
        </tr>
        <tr>
            <td class="label" style="width: 24%;">INDICACIONES DE LA FÓRMULA:</td>
            <td colspan="5" class="instructions-text">
                {{ filled($formulaNotes) ? $formulaNotes : '—' }}
            </td>
        </tr>
    </table>

    {{-- ⑤ MATERIA PRIMA Y CANTIDADES --}}
    <table class="section-table">
        <tr>
            <td colspan="{{ $pdfMode === 'consolidated' ? 5 : 6 }}" class="section-header">MATERIA PRIMA Y CANTIDADES</td>
        </tr>
        @if($pdfMode === 'consolidated')
            <tr>
                <th class="label text-center" style="width: 16%;">COD</th>
                <th class="label text-center" style="width: 11%;">CANT. KG</th>
                <th class="label text-center" style="width: 9%;">CANT. G</th>
                <th class="label text-center" style="width: 46%;">OBSERVACIONES</th>
                <th class="label text-center" style="width: 18%;">AGREGADO TOTAL</th>
            </tr>
            @foreach($pdfRows as $row)
                @php
                    $kg = $row['display_kg'] ?? '0';
                    $grams = $row['display_grams'] ?? '0';
                @endphp
                <tr class="mp-row">
                    <td class="text-center">
                        @if(($row['kind'] ?? 'material') === 'product')
                            {{-- El nombre del producto es largo: un punto menor que el código de una MP, pero del mismo color. --}}
                            <strong class="pt-name">{{ $row['raw_material_code'] }}</strong> <span class="small-text">(PT)</span>
                        @else
                            <strong>{{ $row['raw_material_code'] }}</strong>
                        @endif
                    </td>
                    <td class="text-center">{{ $kg > 0 ? NumberFormat::quantity($kg) : '' }}</td>
                    <td class="text-center">{{ $grams > 0 ? NumberFormat::units($grams) : '' }}</td>
                    <td class="small-text">
                        {{-- De dónde sale el producto, la raya de lo que quedó y lo registrado (BuildProductionOrderPdfMaterialsAction). --}}
                        @foreach($row['observations'] ?? [] as $observation)
                            {{ $observation }}@if(!$loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td class="text-center">
                        {{ isset($row['actual_quantity']) ? NumberFormat::quantity($row['actual_quantity']) : '' }}
                    </td>
                </tr>
            @endforeach
            <tr class="text-bold">
                <td class="text-center label">TOTAL</td>
                <td class="text-center">{{ NumberFormat::quantity($pdfTotals['kg'] ?? 0) }}</td>
                <td class="text-center">{{ (float) ($pdfTotals['grams'] ?? 0) > 0 ? NumberFormat::units($pdfTotals['grams']) : '' }}</td>
                <td></td>
                <td class="text-center">
                    {{ (float) ($pdfTotals['actual_quantity'] ?? 0) > 0 ? NumberFormat::quantity($pdfTotals['actual_quantity']) : '' }}
                </td>
            </tr>
        @else
            <tr>
                <th class="label text-center" style="width: 4%;">#</th>
                <th class="label text-center" style="width: 15.5%;">COD</th>
                <th class="label text-center" style="width: 9.5%;">CANT. KG</th>
                <th class="label text-center" style="width: 7.5%;">CANT. G</th>
                <th class="label text-center" style="width: 45.5%;">OBSERVACIONES</th>
                <th class="label text-center" style="width: 18%;">AGREGADO TOTAL</th>
            </tr>
            @foreach($pdfRows as $row)
                @php
                    $kg = $row['display_kg'] ?? '0';
                    $grams = $row['display_grams'] ?? '0';
                @endphp
                <tr class="mp-row">
                    <td class="text-center">{{ $row['step_order'] }}</td>
                    <td class="text-center">
                        @if(($row['kind'] ?? 'material') === 'product')
                            {{-- El nombre del producto es largo: un punto menor que el código de una MP, pero del mismo color. --}}
                            <strong class="pt-name">{{ $row['raw_material_code'] }}</strong> <span class="small-text">(PT)</span>
                        @else
                            <strong>{{ $row['raw_material_code'] }}</strong>
                        @endif
                    </td>
                    <td class="text-center">{{ $kg > 0 ? NumberFormat::quantity($kg) : '' }}</td>
                    <td class="text-center">{{ $grams > 0 ? NumberFormat::units($grams) : '' }}</td>
                    <td class="small-text">
                        {{-- De dónde sale el producto, la raya de lo que quedó y lo registrado (BuildProductionOrderPdfMaterialsAction). --}}
                        @foreach($row['observations'] ?? [] as $observation)
                            {{ $observation }}@if(!$loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td class="text-center">
                        {{ isset($row['actual_quantity']) ? NumberFormat::quantity($row['actual_quantity']) : '' }}
                    </td>
                </tr>
            @endforeach
            <tr class="text-bold">
                <td class="text-center label" colspan="2">TOTAL</td>
                <td class="text-center">{{ NumberFormat::quantity($pdfTotals['kg'] ?? 0) }}</td>
                <td class="text-center">{{ (float) ($pdfTotals['grams'] ?? 0) > 0 ? NumberFormat::units($pdfTotals['grams']) : '' }}</td>
                <td></td>
                <td class="text-center">
                    {{ (float) ($pdfTotals['actual_quantity'] ?? 0) > 0 ? NumberFormat::quantity($pdfTotals['actual_quantity']) : '' }}
                </td>
            </tr>
        @endif
    </table>

    {{-- ⑤.1 AJUSTES DE LÍNEA (MPs fuera de fórmula) --}}
    @if(!empty($order['line_adjustments']) && count($order['line_adjustments']) > 0)
        <table class="section-table">
            <tr>
                <td colspan="4" class="section-header">AJUSTES DE LÍNEA (MPs ADICIONALES)</td>
            </tr>
            <tr class="compact-row">
                <th class="label text-center" style="width: 20%;">MATERIA PRIMA</th>
                <th class="label text-center" style="width: 18%;">CANTIDAD</th>
                <th class="label text-center" style="width: 36%;">MOTIVO</th>
                <th class="label text-center" style="width: 26%;">OBSERVACIONES</th>
            </tr>
            @foreach($order['line_adjustments'] as $adj)
                <tr class="compact-row">
                    <td class="text-center">{{ $adj['raw_material']['code'] ?? 'N/A' }}</td>
                    <td class="text-center">{{ NumberFormat::quantity($adj['quantity']) }}</td>
                    <td>{{ $adj['reason'] }}</td>
                    <td>{{ $adj['notes'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Los saldos de PT a usar ya no van aparte: son fuentes de su producto, en la tabla de arriba. --}}

    {{-- ⑥ NOMBRE RESPONSABLE (fila compacta) --}}
    <table class="section-table">
        <tr class="compact-row">
            <td class="label" style="width: 24%;">NOMBRE RESPONSABLE</td>
            <td class="value" style="width: 76%; padding: 3pt 6pt;">
                {{ $order['responsible_name'] ?? '' }}
            </td>
        </tr>
    </table>

    {{-- ⑦ RESULTADOS DE CALIDAD (con rangos esperados y Sólidos %) --}}
    <table class="section-table">
        <tr>
            <td colspan="6" class="section-header">RESULTADOS DE CALIDAD</td>
        </tr>
        <tr>
            <td class="label" style="width: 22%;">
                MOLIENDA (HG)
                @if($finenessRange)
                    <span class="expected-range">{{ $finenessRange }}</span>
                @endif
            </td>
            <td class="value text-center" style="width: 11%;">
                {{ $order['grinding_hg'] !== null ? NumberFormat::measurement($order['grinding_hg'], 1) : '' }}
            </td>
            <td class="label" style="width: 20%;">TIEMPO TOTAL MOLIENDA</td>
            <td class="value text-center" style="width: 11%;"></td>
            <td class="label" style="width: 18%;">
                SÓLIDOS (%)
                @if($solidsRange)
                    <span class="expected-range">{{ $solidsRange }}</span>
                @endif
            </td>
            <td class="value text-center" style="width: 18%;">
                {{ ($order['quality_solids'] ?? null) !== null ? NumberFormat::measurement($order['quality_solids'], 1) : '' }}
            </td>
        </tr>
        <tr>
            <td class="label">
                VISCOSIDAD A 25 °C (KU)
                @if($viscosityRange)
                    <span class="expected-range">{{ $viscosityRange }}</span>
                @endif
            </td>
            <td class="value text-center">
                {{ $order['viscosity_ku'] !== null ? NumberFormat::measurement($order['viscosity_ku'], 1) : '' }}
            </td>
            <td class="label">AJUSTES PARA LA VISCOSIDAD</td>
            <td class="value adjustment-cell" colspan="3">
                SÍ <span class="check-box">&nbsp;</span> NO <span class="check-box">&nbsp;</span>
                <span class="small-text">Ajustes realizados:</span>
            </td>
        </tr>
        <tr>
            <td class="label">CORRIDO DE PODER CUBRIENTE</td>
            <td class="value text-center"></td>
            <td class="label">AJUSTES PARA EL PODER CUBRIENTE</td>
            <td class="value adjustment-cell" colspan="3">
                SÍ <span class="check-box">&nbsp;</span> NO <span class="check-box">&nbsp;</span>
                <span class="small-text">Ajustes realizados:</span>
            </td>
        </tr>
    </table>

    {{-- ⑧ OBSERVACIONES FINALES (notas de la orden + renglones para escribir) --}}
    <table class="section-table">
        <tr>
            <td class="section-header">OBSERVACIONES FINALES</td>
        </tr>
        @if(filled($order['notes'] ?? null))
            <tr>
                <td class="value" style="padding: 3pt 5pt;">
                    <strong>Notas de la orden:</strong> {{ $order['notes'] }}
                </td>
            </tr>
        @endif
        @for($i = 0; $i < $finalNotesLines; $i++)
            <tr>
                <td class="write-line">&nbsp;</td>
            </tr>
        @endfor
    </table>

</body>

</html>
