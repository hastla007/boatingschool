<?php

namespace Tests\Feature;

use App\Mail\VerifySupportEmail;
use App\Models\Coupon;
use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste):
 * CouponRedemptionService::redeemForCourse()/redeemForRenewal() werfen
 * zwei Fehlermeldungen, die sowohl bei der Selbst-Einlösung
 * (CouponRedeemController, Lernerbereich siezt bewusst nicht) als auch
 * bei Admin\CouponController::assign() (Bootsschul-Admin weist einem
 * Teilnehmer direkt einen Code zu) auftreten können -- die fest
 * eingebaute Du-Form war dafür nur im ersten Fall richtig. Die Methoden
 * unterscheiden die beiden Fälle jetzt daran, ob $actor (der Handelnde)
 * mit $user (dem Begünstigten) übereinstimmt.
 */
class CouponRedemptionMessageAddressFormTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_learner_redeeming_an_already_owned_course_coupon_still_sees_the_informal_du_form(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $code = 'E2E-DU-SELF-'.strtoupper(uniqid());
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(
            'Du hast diesen Kurs bereits freigeschaltet. Für eine Verlängerung wird ein Verlängerungscode benötigt.',
            session('errors')->first('code')
        );
    }

    public function test_an_admin_assigning_an_already_owned_course_coupon_sees_a_third_person_message_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $code = 'E2E-SIE-ASSIGN-'.uniqid();
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learner->id,
        ]);

        $response->assertSessionHasErrors('assign');
        $message = session('errors')->first('assign');
        $this->assertSame(
            'Dieser Nutzer hat diesen Kurs bereits freigeschaltet. Für eine Verlängerung wird ein Verlängerungscode benötigt.',
            $message
        );
        $this->assertStringNotContainsString('Du hast', $message);
    }

    public function test_a_learner_redeeming_a_renewal_code_for_a_revoked_entitlement_still_sees_the_informal_referral(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'revoked',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(30),
            'valid_until' => now()->addDays(300),
        ]));

        $code = 'E2E-DU-RENEW-'.strtoupper(uniqid());
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => true]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(
            'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Verlängerungscode reaktiviert werden. Bitte wende dich an deine Bootsschule.',
            session('errors')->first('code')
        );
    }

    public function test_an_admin_assigning_a_renewal_code_to_a_revoked_entitlement_sees_no_self_referral(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'revoked',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(30),
            'valid_until' => now()->addDays(300),
        ]));

        $code = 'E2E-SIE-RENEW-'.uniqid();
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => true]));

        $response = $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learner->id,
        ]);

        $response->assertSessionHasErrors('assign');
        $message = session('errors')->first('assign');
        $this->assertSame(
            'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Verlängerungscode reaktiviert werden.',
            $message
        );
        $this->assertStringNotContainsString('wende dich', $message);
    }

    /**
     * Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste): die Mail geht
     * an die Support-Adresse, die der Admin selbst im Branding-Tab
     * hinterlegt hat -- Betreff und Text duzten trotzdem.
     */
    public function test_the_support_email_verification_mail_uses_the_formal_sie_form(): void
    {
        $mail = new VerifySupportEmail('Bootsschule Müller', 'https://example.test/verify/123');
        $mail->build();

        $this->assertSame('Bitte bestätigen Sie die Support-E-Mail für Bootsschule Müller', $mail->subject);

        $rendered = $mail->render();
        $this->assertStringContainsString('bitte bestätigen Sie die Support-E-Mail-Adresse', $rendered);
        $this->assertStringContainsString('indem Sie auf den folgenden Link klicken', $rendered);
        $this->assertStringContainsString('Wenn Sie diese Änderung nicht veranlasst haben', $rendered);
        $this->assertStringNotContainsString('indem du', $rendered);
        $this->assertStringNotContainsString('kannst du', $rendered);
    }
}
