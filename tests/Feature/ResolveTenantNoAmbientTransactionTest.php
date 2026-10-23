<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R3-7: ResolveTenant::handle() wickelte vormals den gesamten
 * restlichen Request (inklusive aller Controller-Queries) in einer
 * DB::transaction() ab -- nur damit SET LOCAL app.current_tenant_id (siehe
 * TenantContext) am Transaktionsende zuverlässig zurückgesetzt wird. Jedes
 * im Request erworbene DB-Lock blieb dadurch bis zum Request-Ende bestehen
 * (auch während des synchronen SMTP-Versands über BestEffortMail -- keine
 * Notification implementiert ShouldQueue, siehe README.md), und die
 * vorgetäuschte Atomarität griff ohnehin nicht, da die meisten Fehlerpfade
 * (BestEffortMail, ProfileController::confirmEmail()) ihre Exception
 * bereits selbst fangen, die Transaktion also trotzdem normal committete.
 */
class ResolveTenantNoAmbientTransactionTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_request_does_not_run_inside_an_ambient_transaction(): void
    {
        Route::middleware('web')->get('/__r3-7-probe', fn () => response()->json([
            'level' => DB::transactionLevel(),
        ]));

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $response = $this->actingAsInTenant($user, $tenant)->get('/__r3-7-probe');

        $response->assertOk();
        $this->assertSame(
            0,
            $response->json('level'),
            'Der Request-Handler soll nicht mehr innerhalb einer von ResolveTenant geöffneten Transaktion laufen -- sonst bleiben darin erworbene Locks bis zum Request-Ende bestehen.'
        );
    }

    /**
     * Die eigentliche Sicherheitsreview-M5-Garantie darf durch den Wegfall
     * der umschließenden Transaktion nicht verloren gehen: app.current_tenant_id
     * darf nie über das Request-Ende hinaus auf der (ggf. wiederverwendeten)
     * Verbindung bestehen bleiben -- jetzt per app()->terminating()-Callback
     * statt per Transaktionsgrenze sichergestellt.
     */
    public function test_the_tenant_guc_is_reset_once_the_request_has_terminated(): void
    {
        Route::middleware('web')->get('/__r3-7-probe', fn () => response()->json(['ok' => true]));

        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant);

        $this->actingAsInTenant($user, $tenant)->get('/__r3-7-probe')->assertOk();

        $value = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS value")->value;

        $this->assertTrue(
            $value === null || $value === '',
            'app.current_tenant_id blieb nach Request-Ende auf der Verbindung gesetzt: '.var_export($value, true)
        );
    }
}
