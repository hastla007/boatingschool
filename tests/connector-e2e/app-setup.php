<?php

/*
| Nur für den Connector-E2E-Test (tests/connector-e2e/run.sh): legt in der
| App eine Testschule mit Schuladmin an und erzeugt für diesen einen
| Verknüpfungscode -- wie ShopLinkController::generate, nur ohne Browser.
| Schreibt über die Owner-Verbindung (pgsql_admin), wie die Seeder.
|
| Aufruf: php tests/connector-e2e/app-setup.php <admin-email>
| Ausgabe (stdout, eine Zeile JSON): {"tenant_id": "...", "link_code": "XXXX-XXXX"}
*/

use App\Models\CommerceLinkCode;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = strtolower($argv[1] ?? '');
if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Aufruf: php tests/connector-e2e/app-setup.php <admin-email>\n");
    exit(2);
}

DB::setDefaultConnection('pgsql_admin');

$result = DB::transaction(function () use ($email) {
    $tenant = Tenant::updateOrCreate(
        ['slug' => 'connector-e2e'],
        ['name' => 'Connector-E2E Bootsschule', 'status' => 'active', 'default_locale' => 'de-DE']
    );

    $user = User::where('email', $email)->first() ?? new User(['email' => $email]);
    $user->forceFill([
        'display_name' => 'E2E Schuladmin',
        'password' => Hash::make(Str::random(32)),
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    TenantUser::withoutGlobalScopes()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'user_id' => $user->id],
        ['role' => 'admin', 'status' => 'active']
    );

    $code = CommerceLinkCode::generate();
    CommerceLinkCode::create([
        'tenant_id' => $tenant->id,
        'created_by_user_id' => $user->id,
        'code_hash' => CommerceLinkCode::hash($code),
        'expires_at' => now()->addMinutes((int) config('commerce.link_code_minutes', 15)),
        'created_at' => now(),
    ]);

    return ['tenant_id' => $tenant->id, 'link_code' => $code];
});

echo json_encode($result), "\n";
