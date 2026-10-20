@php
    $dimensions = ['a4' => [297, 210], 'a5' => [210, 148], 'a6' => [148, 105]];
    [$pageWidthMm, $pageHeightMm] = $dimensions[$size] ?? $dimensions['a4'];
    $fontSizes = [
        'a4' => ['sender' => '10pt', 'recipient' => '18pt'],
        'a5' => ['sender' => '9pt', 'recipient' => '15pt'],
        'a6' => ['sender' => '8pt', 'recipient' => '11pt'],
    ][$size] ?? ['sender' => '10pt', 'recipient' => '18pt'];

    $senderName = $branding->legal_name ?: $tenant->name;
    $recipientName = $order->user->name ?? '—';

    // Der Empfänger-Block wird im normalen Textfluss positioniert (Absender
    // -> Abstandshalter -> Empfänger) statt per position:absolute --
    // deutlich zuverlässiger in dompdf als mehrfach verschachtelte absolut
    // positionierte Elemente und einfacher an die Vorgabe (siehe
    // WillkommensboxLabelController) kalibrierbar.
    $contentWidthMm = $pageWidthMm - 16 - 12;
    $contentHeightMm = $pageHeightMm - 16 - 12;
    $spacerHeightMm = round($contentHeightMm * 0.12, 1);
    $recipientIndentMm = round($contentWidthMm * 0.38, 1);
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

        .frame {
            position: absolute;
            top: 8mm;
            left: 8mm;
            right: 8mm;
            bottom: 8mm;
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
            line-height: 1.5;
            color: #1e293b;
        }

        .recipient .country {
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="sender">
            <div class="block-label">Absender:</div>
            {{ $senderName }}<br>
            @if ($branding->street)
                {{ $branding->street }}<br>
            @endif
            @if ($branding->postal_code || $branding->city)
                {{ $branding->postal_code }} {{ $branding->city }}
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
</body>
</html>
