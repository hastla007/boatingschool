@php
    $dimensions = ['a4' => [210, 297], 'a5' => [148, 210], 'a6' => [105, 148]];
    [$pageWidthMm, $pageHeightMm] = $dimensions[$size] ?? $dimensions['a4'];
    $fontSizes = [
        'a4' => ['sender' => '10pt', 'recipient' => '18pt'],
        'a5' => ['sender' => '9pt', 'recipient' => '15pt'],
        'a6' => ['sender' => '8pt', 'recipient' => '11pt'],
    ][$size] ?? ['sender' => '10pt', 'recipient' => '18pt'];

    $senderName = $branding->legal_name ?: $tenant->name;
    $recipientName = $order->user->name ?? '—';
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
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 3mm;
        }

        .sender {
            position: absolute;
            top: 6mm;
            left: 6mm;
            font-size: {{ $fontSizes['sender'] }};
            line-height: 1.5;
            color: #475569;
        }

        .recipient {
            position: absolute;
            top: 46%;
            left: 42%;
            right: 6mm;
            font-size: {{ $fontSizes['recipient'] }};
            line-height: 1.5;
            color: #0f172a;
        }

        .recipient .name {
            font-weight: bold;
        }

        .recipient .country {
            text-transform: uppercase;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="sender">
            <div class="block-label">Absender</div>
            {{ $senderName }}<br>
            @if ($branding->street)
                {{ $branding->street }}<br>
            @endif
            @if ($branding->postal_code || $branding->city)
                {{ $branding->postal_code }} {{ $branding->city }}
            @endif
        </div>

        <div class="recipient">
            <div class="block-label">Empfänger</div>
            <span class="name">{{ $recipientName }}</span><br>
            {{ $order->street }}<br>
            {{ $order->postal_code }} {{ $order->city }}<br>
            <span class="country">{{ $order->country }}</span>
        </div>
    </div>
</body>
</html>
