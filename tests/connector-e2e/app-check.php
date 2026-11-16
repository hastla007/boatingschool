<?php

/*
| Nur für den Connector-E2E-Test (tests/connector-e2e/run.sh): prüft in der
| App-Datenbank, was die Zustellung einer Shop-Bestellung hinterlassen muss.
|
| Aufruf: php tests/connector-e2e/app-check.php account <tenant-id> <shop-customer-id>
|         php tests/connector-e2e/app-check.php batch <tenant-id> <order-id> <menge> <kurscode>
| Exit 0 = erfüllt, 1 = nicht (noch) erfüllt; Ausgabe: eine Zeile Befund.
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Lesend über die Owner-Verbindung: die Prüfung soll nicht von RLS abhängen.
$db = DB::connection('pgsql_admin');
$shopId = (string) config('commerce.shop_id');
[$check, $tenantId] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($check === 'account') {
    $account = $db->table('commerce_shop_account')->where('shop_id', $shopId)->where('shop_customer_id', $argv[3] ?? '')->first();
    if (! $account) {
        echo "Shopkonto {$argv[3]} in der App unbekannt\n";
        exit(1);
    }
    echo "Shopkonto {$account->shop_customer_id}: Status {$account->status}, Schule ".($account->tenant_id ?? '-')."\n";
    exit($account->status === 'linked' && $account->tenant_id === $tenantId ? 0 : 1);
}

if ($check === 'batch') {
    [$orderId, $quantity, $courseCode] = [$argv[3] ?? '', (int) ($argv[4] ?? 0), $argv[5] ?? ''];
    $batch = $db->table('coupon_batch')->where('source', 'woocommerce')->where('shop_id', $shopId)->where('order_id', $orderId)->first();
    if (! $batch) {
        echo "Kein Codepaket zur Bestellung {$orderId}\n";
        exit(1);
    }
    $course = $db->table('course_definition')->where('id', $batch->course_id)->value('code');
    $codes = $db->table('coupon')->where('batch_id', $batch->id)->where('tenant_id', $tenantId)
        ->whereNull('redeemed_at')->whereNull('voided_at')->count();
    echo "Codepaket {$batch->id}: Schule {$batch->tenant_id}, Kurs {$course}, Menge {$batch->quantity}, Status {$batch->status}, {$codes} offene Codes der Schule\n";
    $ok = $batch->tenant_id === $tenantId && $course === $courseCode && (int) $batch->quantity === $quantity && $codes === $quantity;
    exit($ok ? 0 : 1);
}

fwrite(STDERR, "Unbekannte Prüfung: {$check}\n");
exit(2);
