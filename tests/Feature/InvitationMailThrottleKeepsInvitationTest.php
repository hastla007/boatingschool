<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\ParticipantInviter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review: die tägliche Mail-Drossel je Adresse (höchstens eine
 * Einladungsmail je Adresse, Schule und Tag) brach die Einladung bisher
 * VOR openInvitation() ab -- am selben Tag ins Team geholt, entstand keine
 * Team-Einladung (keine Rolle), ein beim erneuten Einladen angegebener Code
 * wurde nicht reserviert, obwohl die Antwort nach Erfolg klang. Gedrosselt
 * wird jetzt nur die Mail; die Antwort bleibt für neue Adressen und
 * Bestandskonten gleich (kein Konto-Orakel).
 */
class InvitationMailThrottleKeepsInvitationTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Entitlement::where('source_reference', $code)->delete();
                Coupon::where('code', $code)->delete();
            }
            User::where('email', 'like', 'throttle-new-%')->get()->each(function (User $user) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->delete();
            });
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** @return list<string> neue Adresse und Adresse eines Bestandskontos einer anderen Schule */
    private function addresses(): array
    {
        $existing = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner', ['email_verified_at' => now()]);

        return ['throttle-new-'.Str::lower(Str::random(8)).'@example.test', $existing->email];
    }

    public function test_a_team_invitation_on_the_same_day_as_a_participant_invitation_is_still_stored_with_its_role(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $addresses = $this->addresses();
        $answers = [];
        foreach ($addresses as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Ina Neu', 'email' => $email])
                ->assertSessionHas('status', 'Einladung verschickt.');
            $answers[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Ina Ausbilderin', 'email' => $email, 'role' => 'instructor'])
                ->assertSessionHasNoErrors()->getSession()->get('status');

            $teamInvitation = $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $email)->where('role', 'instructor')->first());
            $this->assertNotNull($teamInvitation, "Team-Einladung für {$email} fehlt");
            $this->assertNull($teamInvitation->accepted_at);
            $this->assertSame('Ina Ausbilderin', $teamInvitation->name);
        }

        // Kein Konto-Orakel: dieselbe Antwort für beide Adressen.
        $this->assertSame(array_fill(0, 2, ParticipantInviter::ALREADY_INVITED_STATUS), $answers);

        // Das von hier angelegte, nie aktivierte Konto trägt die neue Rolle schon an der Mitgliedschaft.
        $newUserId = $this->onAdmin(fn () => User::where('email', $addresses[0])->value('id'));
        $this->assertSame('instructor', $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $newUserId)->value('role')));
    }

    public function test_reinviting_with_a_code_on_the_same_day_reserves_the_code_and_says_so(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $answers = [];
        foreach ($this->addresses() as $i => $email) {
            $code = $this->createdCodes[] = 'THR'.$i.'-'.Str::upper(Str::random(4)).'-ABCD';
            $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email])
                ->assertSessionHas('status', 'Einladung verschickt.');
            $status = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => $code])
                ->assertSessionHasNoErrors()->getSession()->get('status');
            $answers[] = str_replace($code, 'CODE', $status);

            $this->assertSame($code, $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $email)->where('role', 'learner')->value('coupon_code')));
            $this->assertFalse($this->onAdmin(fn () => Coupon::where('code', $code)->first()->isRedeemed()));
        }

        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame(ParticipantInviter::ALREADY_INVITED_STATUS.' Code "CODE" ist für diese Person reserviert und wird eingelöst, sobald sie die Einladung annimmt.', $answers[0]);
    }

    public function test_the_throttled_reinvitation_sends_no_second_mail(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        [, $existingEmail] = $this->addresses();

        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $existingEmail]);
        $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Gast', 'email' => $existingEmail, 'role' => 'instructor']);

        Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 1);
    }
}
