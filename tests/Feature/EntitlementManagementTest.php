<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\GermanTime;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B14 (Admin\EntitlementController): "gültig bis heute" führte zu
 * einem 500er, weil ein reines Datum als Mitternacht (Tagesbeginn)
 * gespeichert wurde -- das lag vor valid_from (jetzt) und verletzte den
 * DB-Check-Constraint; außerdem endete der Zugang dadurch schon am
 * gewählten Tag selbst statt an seinem Ende. Ein Doppelklick auf
 * "Freischalten" konnte zwei Entitlement-Zeilen für denselben Kurs anlegen.
 * "Verlängern" und "Sperren" existierten im Controller, aber ohne jede
 * Oberfläche.
 *
 * Bug-Review (Hoch): store() ("Kurs freischalten" im Teilnehmer-Tab) legte
 * bisher IMMER ein unbefristetes Entitlement an (valid_until frei wählbar,
 * Default null), ohne jemals einen Coupon-Code der Bootsschule zu
 * verbrauchen -- obwohl das Dropdown im Teilnehmer-Tab ausdrücklich den
 * verfügbaren Code-Bestand je Kurs anzeigt und bei 0 deaktiviert. store()
 * verlangt jetzt einen verfügbaren, der Bootsschule gehörenden Kurs-Coupon
 * und verbraucht ihn (valid_until kommt seitdem, wie bei jeder anderen
 * Coupon-Einlösung, aus tenant_branding.course_validity_days). Die
 * valid_until-Tests unten für store() sind damit hinfällig -- "Verlängern"
 * (update()/extend) bleibt unverändert admin-frei wählbar.
 */
class EntitlementManagementTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        // Bug-Review B14 (Folgefehler): travelTo() friert die Zeit über das
        // Testende hinaus ein, wenn hier nicht zurückgesetzt wird --
        // andernfalls liefen alle nachfolgenden Tests im selben Prozess
        // mit einer falschen "jetzt"-Zeit weiter.
        $this->travelBack();

        parent::tearDown();
    }

    private function makeCourseCoupon(Tenant $tenant, CourseDefinition $course): Coupon
    {
        return $this->onAdmin(fn () => Coupon::create([
            'code' => 'TEST-ENT-'.uniqid(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));
    }

    public function test_extending_an_entitlement_with_a_date_in_the_past_returns_a_validation_error_instead_of_crashing(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => now()->subDays(2)->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
    }

    public function test_extending_an_entitlement_valid_until_today_between_11pm_and_midnight_utc_returns_a_validation_error_instead_of_crashing(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 23:30:00', 'UTC'));

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $originalValidUntil = $entitlement->valid_until;

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => '2026-01-15',
        ]);

        $response->assertSessionHasErrors('valid_until');
        $this->assertFalse($response->isServerError());
        $this->assertEquals($originalValidUntil, $entitlement->fresh()->valid_until);
    }

    /**
     * Bug-Review N6: status wechselt nie automatisch zurück auf "active"
     * (nur ExpireEntitlements setzt ihn Richtung "expired") --
     * isCurrentlyActive() verlangt aber zwingend status=active,
     * unabhängig von valid_until. "Verlängern" eines bereits
     * abgelaufenen (status=expired) Zugangs blieb deshalb trotz neuem,
     * zukünftigem valid_until wirkungslos gesperrt.
     */
    public function test_extending_an_already_expired_entitlement_reactivates_access(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'expired',
            'source_type' => 'manual',
            'valid_from' => now()->subDays(400),
            'valid_until' => now()->subDays(30),
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", [
            'action' => 'extend',
            'valid_until' => now()->addDays(365)->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $this->onAdmin(fn () => $entitlement->fresh());
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->isCurrentlyActive());

        $access = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $access->assertOk();
    }

    public function test_double_submitting_the_grant_form_does_not_create_a_duplicate_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->makeCourseCoupon($tenant, $course);

        $payload = ['user_id' => $learner->id, 'course_id' => $course->id];
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', $payload)->assertSessionHasNoErrors();
        // Bug-Review (Hoch): eine bereits AKTIVE Freischaltung ist jetzt ein
        // kostenloser No-Op (siehe store()) -- der zweite Klick braucht
        // deshalb keinen zweiten Code, nur EIN Coupon ist hier bewusst
        // angelegt.
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->count()));
        $this->assertNotNull($this->onAdmin(fn () => $coupon->fresh()->redeemed_at));
    }

    /**
     * Bug-Review (Hoch): store() verlangt jetzt einen verfügbaren Coupon --
     * ohne einen angelegten Coupon muss die Freischaltung mit einem
     * Validierungsfehler abgelehnt werden statt (wie vor dem Fix) immer
     * kostenlos zu gelingen.
     */
    public function test_granting_access_without_an_available_coupon_fails_with_a_clear_error(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertSessionHasErrors('course_id');
        $this->onAdmin(fn () => $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]));
    }

    /**
     * Bug-Review (Hoch): store() leitet valid_until jetzt aus
     * tenant_branding.course_validity_days ab (wie jede andere
     * Coupon-Einlösung), statt es unbefristet zu lassen.
     */
    public function test_granting_access_consumes_a_coupon_and_sets_validity_from_tenant_branding(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->makeCourseCoupon($tenant, $course);

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $days = $this->onAdmin(fn () => $tenant->branding->course_validity_days);

        $this->assertSame('coupon', $entitlement->source_type);
        $this->assertSame($coupon->code, $entitlement->source_reference);
        $this->assertTrue($entitlement->valid_until->isSameDay(now()->addDays($days)));

        $fresh = $this->onAdmin(fn () => $coupon->fresh());
        $this->assertNotNull($fresh->redeemed_at);
        $this->assertSame($learner->id, $fresh->redeemed_by_user_id);
    }

    /**
     * Bug-Review (Manuelle Freischaltung verliert gebündelte
     * Zusatzprodukte): store() baute das Entitlement bisher komplett
     * losgelöst von CouponRedemptionService::redeemForCourse() -- bündelte
     * der hier verbrauchte Coupon zusätzliche Produkte (z. B. die
     * Willkommensbox, siehe coupon_option_product), gingen diese
     * stillschweigend verloren.
     */
    public function test_granting_access_through_the_participant_tab_also_grants_bundled_option_products(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $willkommensbox = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $coupon = $this->makeCourseCoupon($tenant, $course);
        $this->onAdmin(fn () => $coupon->optionProducts()->attach($willkommensbox->id));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($tenant, $learner, $willkommensbox, $coupon) {
            $this->assertDatabaseHas('product_purchase', [
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'product_id' => $willkommensbox->id,
                'source_type' => 'coupon', 'source_reference' => $coupon->code,
            ]);
        });
    }

    /**
     * Bug-Review (Manuelle Freischaltung -- Code beliebig gewählt): ohne
     * ORDER BY war nicht nachvollziehbar, welcher von mehreren freien
     * Codes verbraucht wird. Der Code wird jetzt deterministisch nach
     * Anlagedatum verbraucht (der älteste zuerst).
     */
    public function test_granting_access_consumes_the_oldest_available_coupon_first(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $older = $this->onAdmin(fn () => Coupon::create([
            'code' => 'TEST-ENT-OLD-'.uniqid(), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'created_at' => now()->subDay(),
        ]));
        $this->makeCourseCoupon($tenant, $course);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ])->assertSessionHasNoErrors();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $this->assertSame($older->code, $entitlement->source_reference);
    }

    public function test_granting_access_again_reactivates_a_previously_suspended_entitlement_instead_of_duplicating(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->makeCourseCoupon($tenant, $course);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail());
        // Statt $entitlement->update(...): die Instanz trägt die Connection
        // fest, die beim Laden "default" war -- siehe
        // ExpireAbandonedExamSessionsTest für die ausführliche Begründung.
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'suspended']));

        // Ein gesperrter Zugang ist keine aktive Freischaltung mehr -- die
        // Reaktivierung verbraucht deshalb einen WEITEREN eigenen Code.
        $this->makeCourseCoupon($tenant, $course);
        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->count()));
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('active', $entitlement->status);
    }

    /**
     * Bug-Review F1: anders als "suspended" (siehe Test oben) soll ein
     * bewusst widerrufener ("revoked") Zugang nicht einfach durch einen
     * neuen Code zurückkommen -- analog zu CouponRedemptionService::
     * redeemForRenewal() (Bug-Review R4-7). Vor diesem Fix hat genau dieser
     * Weg den Widerruf stillschweigend aufgehoben, sobald wieder ein freier
     * Code für den Kurs verfügbar war.
     */
    public function test_granting_access_again_does_not_silently_revive_a_revoked_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->makeCourseCoupon($tenant, $course);

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);
        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail());
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'revoked']));

        $coupon = $this->makeCourseCoupon($tenant, $course);
        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);

        $response->assertSessionHasErrors('course_id');

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('revoked', $entitlement->status);
        $this->assertNull($this->onAdmin(fn () => $coupon->fresh()->redeemed_at));
    }

    public function test_admin_can_extend_and_suspend_an_entitlement_through_the_participant_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $detail = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $detail->assertOk();
        $detail->assertSee(route('admin.entitlements.update', $entitlement), false);
        $detail->assertSee('Verlängern');
        $detail->assertSee('Sperren');

        $newValidUntil = now()->addDays(10)->format('Y-m-d');
        $extend = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'extend',
            'valid_until' => $newValidUntil,
        ]);
        $extend->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertTrue($entitlement->valid_until->isSameDay(now()->addDays(10)));
        $this->assertSame('23', GermanTime::format($entitlement->valid_until, 'H'));

        $suspend = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'suspend',
        ]);
        $suspend->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('suspended', $entitlement->status);
    }

    /**
     * Bug-Review F1: "Verlängern" setzte status bisher UNBEDINGT auf
     * "active" zurück -- eine bewusste Sperrung ließ sich dadurch allein
     * durch ein neues Ablaufdatum aufheben, ohne die eigentliche
     * "Entsperren"-Aktion zu durchlaufen.
     */
    public function test_extending_a_suspended_entitlement_does_not_lift_the_suspension(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'suspended']));

        $response = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'extend',
            'valid_until' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('suspended', $fresh->status);
        $this->assertTrue($fresh->valid_until->isSameDay(now()->addDays(10)));
    }

    /**
     * Bug-Review F1: dieselbe Lücke wie oben, nur für "Widerrufen" --
     * zusätzlich die bewusst HÄRTERE Stufe, die laut CouponRedemptionService::
     * redeemForRenewal() (Bug-Review R4-7) ausdrücklich NICHT durch eine
     * reine Datumsänderung unterlaufen werden soll.
     */
    public function test_extending_a_revoked_entitlement_does_not_lift_the_revocation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['status' => 'revoked']));

        $response = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'extend',
            'valid_until' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('revoked', $fresh->status);
    }

    /**
     * Bug-Review F1: es gab bisher keine Oberfläche für "Widerrufen" -- nur
     * "Verlängern"/"Sperren" waren über die Teilnehmerseite erreichbar,
     * obwohl der Controller die Aktion längst unterstützt.
     */
    public function test_admin_can_revoke_and_then_undo_the_revocation_through_the_participant_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $detail = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $detail->assertOk();
        $detail->assertSee('Widerrufen');

        $revoke = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'revoke',
        ]);
        $revoke->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('revoked', $entitlement->status);

        $detailAfterRevoke = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");
        $detailAfterRevoke->assertSee('Widerruf aufheben');

        $undo = $this->actingAsInTenant($admin, $tenant)->patch(route('admin.entitlements.update', $entitlement), [
            'action' => 'reactivate',
        ]);
        $undo->assertRedirect();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::find($entitlement->id));
        $this->assertSame('active', $entitlement->status);
    }

    public function test_an_instructor_does_not_see_the_extend_or_suspend_controls(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $detail = $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$learner->id}");

        $detail->assertOk();
        $detail->assertDontSee('Verlängern');
        $detail->assertDontSee('Sperren');
    }
}
