<?php

/*
| Anbindung des B2B-WooCommerce-Shops (docs/commerce/README.md). Ohne
| COMMERCE_SHOP_SECRET ist die Commerce-API abgeschaltet (alle Aufrufe 401).
*/
return [
    // Kennung der Shop-Installation (steht in jedem Lieferauftrag/Konto).
    'shop_id' => env('COMMERCE_SHOP_ID', 'woocommerce'),

    // Eingehend (Shop -> App): Schlüssel-ID und gemeinsames Geheimnis für die
    // HMAC-Signatur. Für einen Schlüsselwechsel kann vorübergehend ein
    // zweites Paar gelten (COMMERCE_SHOP_KEY_ID_NEXT / _SECRET_NEXT).
    'keys' => array_filter([
        env('COMMERCE_SHOP_KEY_ID', 'shop') => env('COMMERCE_SHOP_SECRET'),
        env('COMMERCE_SHOP_KEY_ID_NEXT', 'shop-next') => env('COMMERCE_SHOP_SECRET_NEXT'),
    ]),

    // Ausgehend (App -> Shop): Provisionierungs-Endpunkt des Connector-Plugins.
    'shop_url' => env('COMMERCE_SHOP_URL'), // z. B. https://shop.example.de
    'outbound_key_id' => env('COMMERCE_OUTBOUND_KEY_ID', 'app'),
    'outbound_secret' => env('COMMERCE_OUTBOUND_SECRET'),

    // Zulässige Uhrzeit-Abweichung einer signierten Anfrage (Sekunden).
    'timestamp_tolerance' => 300,

    // Verknüpfungscode für Schuladmins.
    'link_code_minutes' => 15,

    // Ausgang: Versuche und Abstände (Minuten) bis „fehlgeschlagen“ + Alarm.
    'outbox_backoff_minutes' => [1, 5, 30],

    // Alarm-Empfänger (sonst die Support-Adresse der Plattform).
    'alert_email' => env('COMMERCE_ALERT_EMAIL'),

    // Höchstmenge je Bestellposition.
    'max_quantity' => 500,

    // Höchstzahl Codes je Schule und Kalendertag (Europe/Berlin) über den Shop;
    // 0 = ohne Grenze. Darüber: 429 CODE_QUOTA_EXCEEDED und Alarm-Mail.
    'daily_code_limit' => (int) env('COMMERCE_DAILY_CODE_LIMIT', 1000),
];
