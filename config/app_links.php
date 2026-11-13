<?php

/*
 * App-Deep-Links (Universal Links unter iOS, App Links unter Android):
 * /.well-known/apple-app-site-association und /.well-known/assetlinks.json
 * (AppLinksController). Ohne Konfiguration liefern beide 404.
 */
$list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    // iOS: "<Team-ID>.<Bundle-ID>", mehrere kommagetrennt (z. B. App und Beta-App).
    'ios_app_ids' => $list(env('MOBILE_APP_IOS_APP_ID')),

    // Android: Paketname und SHA-256-Fingerabdrücke des Signaturzertifikats
    // (Play App Signing und ggf. Upload-Schlüssel), kommagetrennt, Format AA:BB:...
    'android_package' => env('MOBILE_APP_ANDROID_PACKAGE'),
    'android_sha256' => $list(env('MOBILE_APP_ANDROID_SHA256')),

    // Pfade, die iOS in der App statt im Browser öffnet (Android legt das im
    // App-Manifest fest). "*" als Platzhalter; "/*/…" deckt den Pfad-Modus
    // CENTRAL_DOMAIN/<slug>/… ab.
    'paths' => $list(env('MOBILE_APP_LINK_PATHS', '/reset-password/*,/*/reset-password/*,/invitations/*,/*/invitations/*,/verify-email/*,/*/verify-email/*')),
];
