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
            margin: 22pt 28pt 34pt 28pt;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            color: #202532;
            line-height: 1.28;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        tr {
            page-break-inside: avoid;
        }

        th,
        td {
            border: 0.75pt solid #d0d4dc;
            padding: 3.2pt 6pt;
            vertical-align: top;
            text-align: left;
        }

        /* ── Cabecera estilo formato técnico ISO ── */
        .header-table td,
        .header-table th {
            vertical-align: middle;
            border: 0.75pt solid #d0d4dc;
        }

        .logo-cell {
            width: 22%;
            text-align: center;
            padding: 4pt;
        }

        .logo-img {
            max-height: 36pt;
            max-width: 115pt;
        }

        .logo-fallback {
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
            color: #202532;
        }

        .title-cell {
            width: 48%;
            text-align: center;
            padding: 4pt 6pt;
        }

        .doc-title {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #202532;
            letter-spacing: 0.3pt;
        }

        .doc-subtitle {
            font-size: 8pt;
            color: #606e80;
            margin-top: 2pt;
        }

        .meta-label {
            width: 12%;
            background-color: #e7e9ee;
            color: #202532;
            font-weight: bold;
            font-size: 7pt;
            text-transform: uppercase;
            padding: 2.5pt 4pt;
        }

        .meta-value {
            width: 18%;
            font-size: 8pt;
            color: #202532;
            padding: 2.5pt 4pt;
        }

        /* ── Secciones y grilla de 2 columnas (Sentence case) ── */
        .section-table {
            margin-top: 6pt;
        }

        .page-break-before {
            page-break-before: always;
            margin-top: 0;
        }

        .section-header {
            background-color: #202532;
            color: #ffffff;
            font-weight: bold;
            font-size: 8.2pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            padding: 3.5pt 6pt;
            border-color: #202532;
        }

        .field-label {
            width: 19%;
            background-color: #e7e9ee;
            color: #202532;
            font-weight: bold;
            font-size: 7.8pt;
        }

        .field-value {
            width: 31%;
            color: #202532;
            font-size: 8.3pt;
            word-wrap: break-word;
        }

        .field-value-full {
            width: 81%;
            color: #202532;
            font-size: 8.3pt;
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
            font-size: 7.2pt;
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
            bottom: -20pt;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 7.2pt;
            color: #606e80;
            border-top: 0.5pt solid #d0d4dc;
            padding-top: 2.5pt;
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

    {{-- 2 A 5. BLOQUES TÉCNICOS DEL PAYLOAD (Pág 1: Secc 1–3 | Pág 2: Secc 4–5) --}}
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
            <table class="section-table {{ $payloadKey === 'application_payload' ? 'page-break-before' : '' }}">
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
