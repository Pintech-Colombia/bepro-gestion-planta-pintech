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
            margin: 26pt 30pt 38pt 30pt;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            color: #202532;
            line-height: 1.3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 0.75pt solid #d0d4dc;
            padding: 4pt 6pt;
            vertical-align: top;
            text-align: left;
        }

        /* ── Cabecera corporativa ── */
        .header-table td,
        .header-table th {
            vertical-align: middle;
        }

        .logo-cell {
            width: 24%;
            text-align: center;
            padding: 6pt;
        }

        .logo-img {
            max-height: 44pt;
            max-width: 135pt;
        }

        .logo-fallback {
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
            color: #202532;
        }

        .title-cell {
            width: 44%;
            text-align: center;
            padding: 6pt 8pt;
        }

        .doc-title {
            font-size: 11.5pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #202532;
            letter-spacing: 0.2pt;
        }

        .doc-subtitle {
            font-size: 8.5pt;
            color: #606e80;
            margin-top: 2pt;
        }

        .meta-label {
            width: 13%;
            background-color: #e7e9ee;
            color: #202532;
            font-weight: bold;
            font-size: 7.5pt;
            text-transform: uppercase;
            padding: 3pt 5pt;
        }

        .meta-value {
            width: 19%;
            font-size: 8.5pt;
            padding: 3pt 5pt;
        }

        /* ── Secciones y grilla de 2 columnas ── */
        .section-table {
            margin-top: 7pt;
        }

        .section-header {
            background-color: #202532;
            color: #ffffff;
            font-weight: bold;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            padding: 4pt 6pt;
            border-color: #202532;
        }

        .field-label {
            width: 20%;
            background-color: #e7e9ee;
            color: #202532;
            font-weight: bold;
            font-size: 8pt;
        }

        .field-value {
            width: 30%;
            color: #202532;
            font-size: 8.5pt;
            word-wrap: break-word;
        }

        .field-value-full {
            width: 80%;
            color: #202532;
            font-size: 8.5pt;
            word-wrap: break-word;
        }

        .pre-line {
            white-space: pre-line;
        }

        /* ── Badges de estado (tonos de DESIGN.md) ── */
        .badge {
            display: inline-block;
            padding: 1.5pt 6pt;
            border-radius: 8pt;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-draft {
            background-color: #e7e9ee;
            color: #606e80;
        }

        .badge-submitted {
            background-color: #e0f0fb;
            color: #1259a1;
        }

        .badge-in_review {
            background-color: #fef3db;
            color: #955104;
        }

        .badge-approved {
            background-color: #dcf2e4;
            color: #137236;
        }

        .badge-rejected {
            background-color: #fae1e1;
            color: #b81e1e;
        }

        /* ── Pie de página fijo ── */
        .footer {
            position: fixed;
            bottom: -22pt;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 7.5pt;
            color: #606e80;
            border-top: 0.5pt solid #d0d4dc;
            padding-top: 3pt;
        }
    </style>
</head>
<body>
    {{-- CABECERA CORPORATIVA --}}
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
            <th class="meta-label">Solicitud</th>
            <td class="meta-value"><strong>SDR-{{ $request['request_number'] }}</strong></td>
        </tr>
        <tr>
            <th class="meta-label">Estado</th>
            <td class="meta-value">
                <span class="badge badge-{{ $request['status'] }}">{{ $request['status_label'] }}</span>
            </td>
        </tr>
        <tr>
            <th class="meta-label">Fecha</th>
            <td class="meta-value">{{ $request['created_at'] ?? '—' }}</td>
        </tr>
    </table>

    {{-- 1. IDENTIFICACIÓN --}}
    <table class="section-table">
        <tr>
            <td colspan="4" class="section-header">1. Identificación del proyecto</td>
        </tr>
        <tr>
            <th class="field-label">Proyecto</th>
            <td class="field-value"><strong>{{ $request['project_name'] }}</strong></td>
            <th class="field-label">Cliente</th>
            <td class="field-value">{{ filled($request['client_name'] ?? null) ? $request['client_name'] : '—' }}</td>
        </tr>
        <tr>
            <th class="field-label">Responsable</th>
            <td class="field-value">{{ $request['responsible'] }}</td>
            <th class="field-label">Ciudad / región</th>
            <td class="field-value">{{ $request['city'] }}</td>
        </tr>
        <tr>
            <th class="field-label">Fecha req. muestra</th>
            <td class="field-value">{{ $request['sample_due_date'] ?? '—' }}</td>
            <th class="field-label">Producto actual</th>
            <td class="field-value">{{ filled($request['current_product'] ?? null) ? $request['current_product'] : '—' }}</td>
        </tr>
    </table>

    {{-- 2 A 5. BLOQUES TÉCNICOS DEL PAYLOAD --}}
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
                <tr>
                    <td colspan="4" class="section-header">{{ $sectionTitle }}</td>
                </tr>
                @foreach($rows as $row)
                    @if(count($row) === 2)
                        <tr>
                            <th class="field-label">{{ $row[0]['label'] }}</th>
                            <td class="field-value">{{ $row[0]['value'] }}</td>
                            <th class="field-label">{{ $row[1]['label'] }}</th>
                            <td class="field-value">{{ $row[1]['value'] }}</td>
                        </tr>
                    @else
                        <tr>
                            <th class="field-label">{{ $row[0]['label'] }}</th>
                            <td colspan="3" class="field-value-full pre-line">{{ $row[0]['value'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        @endif
    @endforeach

    {{-- 6. REVISIÓN --}}
    @if(!empty($request['review_notes']) || !empty($request['reviewed_at']) || !empty($request['reviewer']['name']))
        <table class="section-table">
            <tr>
                <td colspan="4" class="section-header">6. Revisión técnica</td>
            </tr>
            <tr>
                <th class="field-label">Revisado por</th>
                <td class="field-value">{{ $request['reviewer']['name'] ?? '—' }}</td>
                <th class="field-label">Fecha de revisión</th>
                <td class="field-value">{{ $request['reviewed_at'] ?? '—' }}</td>
            </tr>
            @if(!empty($request['review_notes']))
                <tr>
                    <th class="field-label">Notas de revisión</th>
                    <td colspan="3" class="field-value-full pre-line">{{ $request['review_notes'] }}</td>
                </tr>
            @endif
        </table>
    @endif

    <div class="footer">
        Solicitud SDR-{{ $request['request_number'] }} &nbsp;·&nbsp; Generado el {{ $generatedAt }} — Pintech OS
    </div>
</body>
</html>
