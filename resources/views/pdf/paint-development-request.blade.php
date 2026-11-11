<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Solicitud {{ $request['request_number'] }}</title>
    <style>
        /*
         * No usar `* { margin: 0 }`: en DomPDF alcanza a `@page` y anula los márgenes de impresión.
         */
        @page {
            margin: 14pt 22pt 22pt 22pt;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.4pt;
            color: #111111;
            line-height: 1.15;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            border: 0.5pt solid #a6a6a6;
            padding: 1.8pt 4pt;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
        }

        /* Fila invisible de 12 celdas para que DomPDF resuelva exacto colspan 3 (25%), 4 (33,3%), 6 (50%) y 12 (100%). */
        tr.grid-sizer td {
            border: none;
            padding: 0;
            height: 0;
            line-height: 0;
            font-size: 0;
            width: 8.333%;
        }

        /* ── Cabecera estilo formato técnico ISO ── */
        .header-table td,
        .header-table th {
            vertical-align: middle;
            border-color: #333333;
        }

        .logo-cell {
            width: 22%;
            text-align: center;
            padding: 2.5pt;
        }

        .logo-img {
            max-height: 30pt;
            max-width: 100pt;
        }

        .logo-fallback {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
            color: #111111;
        }

        .title-cell {
            width: 48%;
            text-align: center;
            padding: 2.5pt 6pt;
        }

        .doc-title {
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #111111;
            letter-spacing: 0.3pt;
        }

        .doc-subtitle {
            font-size: 7.2pt;
            color: #444444;
            margin-top: 1pt;
        }

        .meta-label {
            width: 12%;
            background-color: #efefef;
            color: #222222;
            font-weight: bold;
            font-size: 6.3pt;
            text-transform: uppercase;
            padding: 1.8pt 4pt;
        }

        .meta-value {
            width: 18%;
            font-size: 7.2pt;
            color: #111111;
            padding: 1.8pt 4pt;
        }

        /* ── Bloques técnicos (grilla de 12 columnas con etiqueta arriba + valor abajo) ── */
        .section-table {
            margin-top: 3pt;
        }

        .section-header {
            background-color: #e6e6e6;
            color: #111111;
            font-weight: bold;
            font-size: 6.8pt;
            text-transform: uppercase;
            letter-spacing: 0.25pt;
            padding: 1.8pt 4pt;
            border-top: 0.9pt solid #333333;
            border-bottom: 0.7pt solid #333333;
            border-left: 0.5pt solid #333333;
            border-right: 0.5pt solid #333333;
        }

        .cell-label {
            font-size: 5.7pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #555555;
            letter-spacing: 0.1pt;
            margin-bottom: 0.6pt;
        }

        .cell-value {
            font-size: 7.4pt;
            color: #111111;
            line-height: 1.15;
        }

        .pre-line {
            white-space: pre-line;
        }

        /* ── Pie de página fijo ── */
        .footer {
            position: fixed;
            bottom: -14pt;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 6.3pt;
            color: #666666;
            border-top: 0.5pt solid #bbbbbb;
            padding-top: 1.5pt;
        }
    </style>
</head>
<body>
    {{-- CABECERA DE CONTROL DOCUMENTAL --}}
    <table class="header-table">
        <tr>
            <td rowspan="3" class="logo-cell">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Pintech">
                @else
                    <span class="logo-fallback">PINTECH</span>
                @endif
            </td>
            <td rowspan="3" class="title-cell">
                <div class="doc-title">Solicitud de desarrollo de pintura</div>
                <div class="doc-subtitle">{{ $request['project_name'] }}</div>
            </td>
            <th class="meta-label">Código</th>
            <td class="meta-value"><strong>SDR-{{ $request['request_number'] }}</strong></td>
        </tr>
        <tr>
            <th class="meta-label">Estado</th>
            <td class="meta-value"><strong>{{ mb_strtoupper((string) $request['status_label']) }}</strong></td>
        </tr>
        <tr>
            <th class="meta-label">Fecha</th>
            <td class="meta-value">{{ $request['created_at'] ?? '—' }}</td>
        </tr>
    </table>

    {{-- 1. IDENTIFICACIÓN DEL PROYECTO --}}
    <table class="section-table">
        <tr class="grid-sizer">@for($i = 0; $i < 12; $i++)<td></td>@endfor</tr>
        <tr>
            <td colspan="12" class="section-header">1. Identificación del proyecto</td>
        </tr>
        <tr>
            <td colspan="4">
                <div class="cell-label">Proyecto</div>
                <div class="cell-value"><strong>{{ $request['project_name'] }}</strong></div>
            </td>
            <td colspan="4">
                <div class="cell-label">Cliente</div>
                <div class="cell-value">{{ filled($request['client_name'] ?? null) ? $request['client_name'] : '—' }}</div>
            </td>
            <td colspan="4">
                <div class="cell-label">Responsable</div>
                <div class="cell-value">{{ $request['responsible'] }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="4">
                <div class="cell-label">Ciudad / región</div>
                <div class="cell-value">{{ $request['city'] }}</div>
            </td>
            <td colspan="4">
                <div class="cell-label">Fecha requerida para la muestra</div>
                <div class="cell-value">{{ $request['sample_due_date'] ?? '—' }}</div>
            </td>
            <td colspan="4">
                <div class="cell-label">Producto usado actualmente</div>
                <div class="cell-value">{{ filled($request['current_product'] ?? null) ? $request['current_product'] : '—' }}</div>
            </td>
        </tr>
    </table>

    {{-- 2 A 5. SECCIONES TÉCNICAS DEL PAYLOAD --}}
    @foreach([
        'context_payload' => '2. Contexto — Proyecto, sustrato y exposición',
        'performance_payload' => '3. Desempeño — Función, resistencia y acabado',
        'application_payload' => '4. Aplicación — Método, espesores y tecnología',
        'specifications_payload' => '5. Especificaciones — Control, suministro y aprobación',
    ] as $payloadKey => $sectionTitle)
        @php
            $rows = \App\Models\PaintDevelopmentRequest::pdfPayloadRows($payloadKey, $request[$payloadKey] ?? []);
        @endphp
        @if(count($rows) > 0)
            <table class="section-table">
                <tr class="grid-sizer">@for($i = 0; $i < 12; $i++)<td></td>@endfor</tr>
                <tr>
                    <td colspan="12" class="section-header">{{ $sectionTitle }}</td>
                </tr>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td colspan="{{ $cell['colspan'] }}">
                                <div class="cell-label">{{ $cell['label'] }}</div>
                                <div class="cell-value {{ $cell['full_width'] ? 'pre-line' : '' }}">{{ $cell['value'] }}</div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    {{-- 6. REVISIÓN --}}
    @if(!empty($request['review_notes']) || !empty($request['reviewed_at']) || !empty($request['reviewer']['name']))
        <table class="section-table">
            <tr class="grid-sizer">@for($i = 0; $i < 12; $i++)<td></td>@endfor</tr>
            <tr>
                <td colspan="12" class="section-header">6. Revisión técnica</td>
            </tr>
            <tr>
                <td colspan="6">
                    <div class="cell-label">Revisado por</div>
                    <div class="cell-value">{{ $request['reviewer']['name'] ?? '—' }}</div>
                </td>
                <td colspan="6">
                    <div class="cell-label">Fecha de revisión</div>
                    <div class="cell-value">{{ $request['reviewed_at'] ?? '—' }}</div>
                </td>
            </tr>
            @if(!empty($request['review_notes']))
                <tr>
                    <td colspan="12">
                        <div class="cell-label">Notas de revisión</div>
                        <div class="cell-value pre-line">{{ $request['review_notes'] }}</div>
                    </td>
                </tr>
            @endif
        </table>
    @endif

    <div class="footer">
        Solicitud SDR-{{ $request['request_number'] }} &nbsp;·&nbsp; Generado el {{ $generatedAt }} — Pintech OS
    </div>
</body>
</html>
