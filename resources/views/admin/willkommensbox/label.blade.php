@php
    // A4 und A5 sind kein eigenes Vollformat-Label, sondern ein A6-Label
    // (exakt wie die A6-Vorlage) zentriert auf einem hochkanten Bogen zum
    // Ausschneiden -- die meisten Bootsschulen haben kein A6-Papier
    // vorrätig, A4/A5 dagegen eher.
    $cutGuidePageDimensions = ['a4' => [210, 297], 'a5' => [148, 210]];
    $isCutGuide = array_key_exists($size, $cutGuidePageDimensions);

    if ($isCutGuide) {
        [$pageWidthMm, $pageHeightMm] = $cutGuidePageDimensions[$size];
        $labelWidthMm = 148;
        $labelHeightMm = 105;
    } else {
        $labelWidthMm = 148;
        $labelHeightMm = 105;
        $pageWidthMm = $labelWidthMm;
        $pageHeightMm = $labelHeightMm;
    }

    // Für den Ausschneide-Bogen (A4/A5) gelten dieselben Schriftgrößen wie
    // beim A6-Label selbst -- das Label soll 1:1 identisch aussehen.
    $fontSizes = ['sender' => '10pt', 'recipient' => '14.3pt'];

    $senderName = $branding->legal_name ?: $tenant->name;
    $recipientName = $order->user->name ?? '—';

    // Der Empfänger-Block wird im normalen Textfluss positioniert (Absender
    // -> Abstandshalter -> Empfänger) statt per position:absolute --
    // deutlich zuverlässiger in dompdf als mehrfach verschachtelte absolut
    // positionierte Elemente und einfacher an die Vorgabe (siehe
    // WillkommensboxLabelController) kalibrierbar.
    $contentWidthMm = $labelWidthMm - 8 - 12;
    $contentHeightMm = $labelHeightMm - 8 - 12;
    $spacerHeightMm = 0;
    $recipientIndentMm = round($contentWidthMm * 0.38, 1);

    $labelOffsetTopMm = $isCutGuide ? 20 : 0;
    $labelOffsetLeftMm = $isCutGuide ? round(($pageWidthMm - $labelWidthMm) / 2, 1) : 0;

    // Referenzzettel oberhalb der Schneidlinie (nur auf dem Ausschneide-
    // Bogen, nicht auf dem A6-Label selbst): Empfänger, Inhalt und eine
    // Zeile zum handschriftlichen Eintragen der Trackingnummer -- bleibt
    // bei der Bootsschule, nachdem das Label ausgeschnitten und aufs Paket
    // geklebt wurde.
    $infoBoxTopMm = $labelOffsetTopMm + $labelHeightMm + 15;
    $cutHintTopMm = $pageHeightMm - 20;
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
            position: relative;
            top: -4mm;
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

        .info-box {
            position: absolute;
            top: {{ $infoBoxTopMm }}mm;
            left: 12mm;
            right: 12mm;
            font-size: 10pt;
            line-height: 1.6;
            color: #1e293b;
        }

        .info-box .row {
            margin-bottom: 2mm;
        }

        .info-box .row.spaced {
            margin-top: 4mm;
        }

        .info-box .tracking-row {
            margin-top: 8mm;
        }

        .cut-hint {
            position: absolute;
            top: {{ $cutHintTopMm }}mm;
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

    @if ($isCutGuide)
        <div class="info-box">
            <div class="row"><strong>Name des Empfängers:</strong> {{ $recipientName }}</div>
            <div class="row"><strong>Adresse:</strong> {{ $order->street }}, {{ $order->postal_code }} {{ $order->city }}, {{ $order->country }}</div>
            <div class="row spaced"><strong>Inhalt des Pakets:</strong> Willkommensbox</div>
            <div class="row tracking-row"><strong>Tracking:</strong> ____________________________________________</div>
        </div>

        <div class="cut-hint">An der Linie ausschneiden und auf das Paket kleben.</div>
    @endif
</body>
</html>
