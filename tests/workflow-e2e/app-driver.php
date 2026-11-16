<?php

/*
| Nur für den Workflow-E2E-Test (tests/workflow-e2e/run.sh): Hilfsbefehle auf
| der App-Seite. Bootet die App mit derselben Umgebung wie der laufende
| App-Server (DB_DATABASE, WORKFLOW_*, COMMERCE_*). Ruft die Commerce-API
| über das Netz auf (wie der Shop), liest die Workflow-Tabellen über die
| Owner-Verbindung und gibt eine Zeile JSON aus.
|
|   php tests/workflow-e2e/app-driver.php setup <admin-email>
|   php tests/workflow-e2e/app-driver.php commerce <METHOD> <path> [json] [idempotency-key]
|   php tests/workflow-e2e/app-driver.php events [since-iso]
|   php tests/workflow-e2e/app-driver.php due <event-id>          (Wartezeit der Wiederholung vorziehen)
|   php tests/workflow-e2e/app-driver.php commands <tenant-id>
|   php tests/workflow-e2e/app-driver.php codes <batch-id>        (Codes eines Batches, nur für die Redaktionsprüfung)
*/

use App\Commerce\Signature;
use App\Models\CommerceLinkCode;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Workflow\WorkflowSignature;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$out = [];
switch ($cmd) {
    case 'setup':
        $email = strtolower($argv[2] ?? '');
        $out = DB::connection('pgsql_admin')->transaction(function () use ($email) {
            DB::setDefaultConnection('pgsql_admin');
            $tenant = Tenant::updateOrCreate(['slug' => 'workflow-e2e'], ['name' => 'Workflow-E2E Bootsschule', 'status' => 'active', 'default_locale' => 'de-DE']);
            $user = User::where('email', $email)->first() ?? new User(['email' => $email]);
            $user->forceFill(['display_name' => 'E2E Schuladmin', 'password' => Hash::make(Str::random(32)), 'status' => 'active', 'email_verified_at' => now()])->save();
            TenantUser::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id], ['role' => 'admin', 'status' => 'active']);
            $code = CommerceLinkCode::generate();
            CommerceLinkCode::create(['tenant_id' => $tenant->id, 'created_by_user_id' => $user->id, 'code_hash' => CommerceLinkCode::hash($code),
                'expires_at' => now()->addMinutes(15), 'created_at' => now()]);

            return ['tenant_id' => $tenant->id, 'link_code' => $code];
        });
        break;

    case 'commerce':
        // Signierter Aufruf an die laufende App wie der WooCommerce-Connector (Signature::headers()).
        [$method, $path] = [strtoupper($argv[2] ?? 'GET'), '/api/commerce/v1'.($argv[3] ?? '/')];
        $body = isset($argv[4]) && $argv[4] !== '' ? $argv[4] : ($method === 'GET' ? '' : '{}');
        $headers = Signature::headers((string) (string) env('COMMERCE_SHOP_KEY_ID', 'shop'), (string) env('COMMERCE_SHOP_SECRET'), $method, $path, $body)
            + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (! empty($argv[5])) {
            $headers['Idempotency-Key'] = $argv[5];
        }
        $response = Http::withHeaders($headers)->withBody($body, 'application/json')->send($method, rtrim((string) env('E2E_APP_URL'), '/').$path);
        $out = ['status' => $response->status(), 'body' => $response->json()];
        break;

    case 'events':
        $since = $argv[2] ?? now()->subHour()->toIso8601String();
        $out = DB::connection('pgsql_admin')->table('workflow_event')->where('created_at', '>=', $since)->orderBy('created_at')
            ->get(['id', 'type', 'scope', 'tenant_ref', 'status', 'attempts', 'last_error'])->map(fn ($r) => (array) $r)->all();
        break;

    case 'due':
        $out = ['updated' => DB::connection('pgsql_admin')->table('workflow_event')->where('id', $argv[2] ?? '')->where('status', 'pending')
            ->update(['available_at' => now()->subSecond()])];
        break;

    case 'commands':
        $out = DB::connection('pgsql_admin')->table('workflow_command')->where('tenant_ref', $argv[2] ?? '')->orderBy('created_at')
            ->get(['action', 'status', 'result', 'key_id'])->map(fn ($r) => ['action' => $r->action, 'status' => $r->status, 'key_id' => $r->key_id, 'result' => json_decode((string) $r->result, true)])->all();
        break;

    case 'raw':
        // Signierter Ereignis-Aufruf direkt an das Plugin: Zeitversatz (Sekunden), Nonce zweimal senden (1/0),
        // Geheimnis überschreiben (leer = richtig). Ereignis: shop_account.registered (Plattform, ohne Schule).
        $event = ['id' => (string) Str::uuid(), 'type' => 'shop_account.registered', 'version' => 1, 'occurred_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'scope' => 'platform', 'tenant_id' => null, 'subject' => ['type' => 'shop_account', 'id' => (string) Str::uuid()],
            'correlation_id' => 'shop_account:e2e', 'data' => ['shop_account_id' => (string) Str::uuid(), 'status' => 'pending_review']];
        $body = json_encode(['op' => 'event', 'contract_version' => (string) config('workflow.contract_version'), 'event' => $event], JSON_UNESCAPED_SLASHES);
        $secret = ($argv[4] ?? '') !== '' ? $argv[4] : (string) config('workflow.outbound_secret');
        $headers = WorkflowSignature::headers((string) config('workflow.outbound_key_id'), $secret, $body, time() + (int) ($argv[2] ?? 0))
            + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach (range(1, ($argv[3] ?? '0') === '1' ? 2 : 1) as $_) {
            $r = Http::withHeaders($headers)->withBody($body, 'application/json')->post((string) config('workflow.endpoint'));
            $out[] = ['status' => $r->status(), 'code' => $r->json('code') ?? $r->json('result')];
        }
        break;

    case 'codes':
        $out = DB::connection('pgsql_admin')->table('coupon')->where('batch_id', $argv[2] ?? '')->pluck('code')->all();
        break;

    default:
        fwrite(STDERR, "Unbekannter Befehl.\n");
        exit(2);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
