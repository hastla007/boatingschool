@php
    // A4 ist kein eigenes Vollformat-Label, sondern ein A6-Label (exakt wie
    // die A6-Vorlage) zentriert auf einem A4-Bogen zum Ausschneiden -- die
    // meisten Drucker/Schulen haben kein A6-Papier vorrätig, A4 dagegen
    // immer.
    $isA4CutGuide = $size === 'a4';

    if ($isA4CutGuide) {
        $pageWidthMm = 210;
        $pageHeightMm = 297;
        $labelWidthMm = 148;
        $labelHeightMm = 105;
    } else {
        $dimensions = ['a5' => [210, 148], 'a6' => [148, 105]];
        [$labelWidthMm, $labelHeightMm] = $dimensions[$size] ?? $dimensions['a6'];
        $pageWidthMm = $labelWidthMm;
        $pageHeightMm = $labelHeightMm;
    }

    $fontSizesBySize = [
        'a5' => ['sender' => '11pt', 'recipient' => '15pt'],
        'a6' => ['sender' => '10pt', 'recipient' => '11pt'],
    ];
    // Für den Ausschneide-Bogen gelten dieselben Schriftgrößen wie beim
    // A6-Label selbst -- das Label soll 1:1 identisch aussehen.
    $fontSizes = $isA4CutGuide ? $fontSizesBySize['a6'] : ($fontSizesBySize[$size] ?? $fontSizesBySize['a6']);

    $senderName = $branding->legal_name ?: $tenant->name;
    $recipientName = $order->user->name ?? '—';

    // Der Empfänger-Block wird im normalen Textfluss positioniert (Absender
    // -> Abstandshalter -> Empfänger) statt per position:absolute --
    // deutlich zuverlässiger in dompdf als mehrfach verschachtelte absolut
    // positionierte Elemente und einfacher an die Vorgabe (siehe
    // WillkommensboxLabelController) kalibrierbar.
    $contentWidthMm = $labelWidthMm - 8 - 12;
    $contentHeightMm = $labelHeightMm - 8 - 12;
    $spacerHeightMm = round($contentHeightMm * 0.02, 1);
    $recipientIndentMm = round($contentWidthMm * 0.38, 1);

    $labelOffsetTopMm = $isA4CutGuide ? 20 : 0;
    $labelOffsetLeftMm = $isA4CutGuide ? round(($pageWidthMm - $labelWidthMm) / 2, 1) : 0;
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: {{ $pageWidthMm }}mm {{ $pageHeightMm }}mm;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
            width: {{ $pageWidthMm }}mm;
            height: {{ $pageHeightMm }}mm;
            font-family: "DejaVu Sans", sans-serif;
            color: #1e293b;
        }

        .label-box {
            position: absolute;
            top: {{ $labelOffsetTopMm }}mm;
            left: {{ $labelOffsetLeftMm }}mm;
            width: {{ $labelWidthMm }}mm;
            height: {{ $labelHeightMm }}mm;
        }

        .frame {
            position: absolute;
            top: 4mm;
            left: 4mm;
            right: 4mm;
            bottom: 4mm;
            border: 1.5px dashed #94a3b8;
            box-sizing: border-box;
            padding: 6mm;
        }

        .block-label {
            font-size: 8pt;
            color: #94a3b8;
            margin-bottom: 3mm;
        }

        .sender {
            font-size: {{ $fontSizes['sender'] }};
            line-height: 1.5;
            color: #94a3b8;
        }

        .spacer {
            height: {{ $spacerHeightMm }}mm;
        }

        .recipient {
            margin-left: {{ $recipientIndentMm }}mm;
            font-size: {{ $fontSizes['recipient'] }};
            font-weight: bold;
            line-height: 1.5;
            color: #1e293b;
        }

        .recipient .block-label {
            font-weight: normal;
        }

        .recipient .country {
            text-transform: uppercase;
        }

        .cut-hint {
            position: absolute;
            top: {{ $labelOffsetTopMm + $labelHeightMm + 10 }}mm;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 11pt;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="label-box">
        <div class="frame">
            <div class="sender">
                <div class="block-label">Absender:</div>
                {{ $senderName }}<br>
                @if ($branding->street)
                    {{ $branding->street }}<br>
                @endif
                @if ($branding->postal_code || $branding->city)
                    {{ $branding->postal_code }} {{ $branding->city }}<br>
                @endif
                @if ($branding->country)
                    {{ $branding->country }}
                @endif
            </div>

            <div class="spacer"></div>

            <div class="recipient">
                <div class="block-label">Empfänger:</div>
                {{ $recipientName }}<br>
                {{ $order->street }}<br>
                {{ $order->postal_code }} {{ $order->city }}<br>
                <span class="country">{{ $order->country }}</span>
            </div>
        </div>
    </div>

    @if ($isA4CutGuide)
        <div class="cut-hint">Hier an der Linie ausschneiden und auf das Paket kleben.</div>
    @endif
</body>
</html>
