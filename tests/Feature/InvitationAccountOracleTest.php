<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Kein Konto-Orakel bei Einladungen: eine Schule darf weder an der Antwort
 * noch an der Teilnehmerliste erkennen, ob es zu einer eingeladenen Adresse
 * plattformweit schon ein Konto gibt. Das Verhalten dahinter bleibt
 * verschieden (Bestandskonto: Einladung zum Annehmen; neue Adresse: Konto
 * „invited“ mit Mitgliedschaft), sichtbar ist beides als offene Einladung.
 */
class InvitationAccountOracleTest extends TestCase
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
            User::where('email', 'like', 'oracle-new-%')->get()->each(function (User $user) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->delete();
            });
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** @return array{0: string, 1: User} neue Adresse und ein Bestandskonto einer anderen Schule */
    private function addresses(): array
    {
        $existing = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner', ['email_verified_at' => now()]);

        return ['oracle-new-'.Str::lower(Str::random(8)).'@example.test', $existing];
    }

    public function test_new_and_existing_addresses_get_the_same_response_and_listing(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        [$newEmail, $existing] = $this->addresses();

        $statuses = [];
        foreach ([$newEmail, $existing->email] as $email) {
            $statuses[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast Person', 'email' => $email])
                ->assertSessionHasNoErrors()->baseResponse->getSession()->get('status');
        }
        $this->assertSame(['Einladung verschickt.', 'Einladung verschickt.'], $statuses);

        // Zweiter Versuch am selben Tag: für beide dieselbe Antwort.
        $again = [];
        foreach ([$newEmail, $existing->email] as $email) {
            $again[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast Person', 'email' => $email])
                ->baseResponse->getSession()->get('status');
        }
        $this->assertSame(array_fill(0, 2, 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.'), $again);

        // Beide stehen unter „Offene Einladungen“ mit dem eingegebenen
        // Namen, keiner in der Teilnehmerliste oder der Teilnehmerzahl.
        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $page->assertSee('Offene Einladungen (2)')->assertSee($newEmail)->assertSee($existing->email);
        $page->assertSee('0 Teilnehmer');
        $this->assertSame(2, substr_count($page->getContent(), 'eingeladen, noch nicht angemeldet'));
        $this->assertSame(0, $page->viewData('participants')->total());
        $this->assertSame(0, $page->viewData('learnerCount'));
        $this->assertSame(['Gast Person', 'Gast Person'], $page->viewData('openInvitations')->pluck('name')->all());

        // Sammel-Einladung am selben Tag: beide gleich übersprungen.
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/bulk-invite', ['emails' => $newEmail."\n".$existing->email])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, '0 Personen eingeladen.')
                && str_contains($s, "{$newEmail} (heute schon eingeladen)") && str_contains($s, "{$existing->email} (heute schon eingeladen)"));
    }

    public function test_a_code_is_handled_the_same_and_redeemed_for_an_existing_account_on_acceptance(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        [$newEmail, $existing] = $this->addresses();
        $codes = ['E2E-ORACLE-NEW-'.Str::upper(Str::random(6)), 'E2E-ORACLE-OLD-'.Str::upper(Str::random(6))];
        $this->createdCodes = $codes;
        $this->onAdmin(fn () => array_map(fn ($c) => Coupon::create(['code' => $c, 'course_id' => $course->id, 'tenant_id' => $tenant->id]), $codes));

        $statuses = [];
        foreach ([[$newEmail, $codes[0]], [$existing->email, $codes[1]]] as [$email, $code]) {
            $status = $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => $code])
                ->assertSessionHasNoErrors()->baseResponse->getSession()->get('status');
            $statuses[] = str_replace($code, 'CODE', $status);
        }
        $this->assertSame($statuses[0], $statuses[1]);
        $this->assertSame('Einladung verschickt. Code "CODE" wird dieser Person zugewiesen.', $statuses[0]);

        // Ungültiger Code: für beide derselbe Fehler (frische Adressen, sonst
        // greift die Tagesdrossel „heute schon eingeladen“ zuerst).
        [$otherNew, $otherExisting] = $this->addresses();
        foreach ([$otherNew, $otherExisting->email] as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => 'UNBEKANNT-0000'])
                ->assertSessionHasErrors(['code' => 'Dieser Code ist ungültig oder wurde bereits eingelöst.']);
        }

        // Bestandskonto: vorgemerkt, eingelöst erst beim Annehmen.
        $invitation = $this->asTenant($tenant, fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $existing->email)->firstOrFail());
        $this->assertSame($codes[1], $invitation->coupon_code);
        $this->assertSame('Gast', $invitation->name);
        $this->assertFalse($this->onAdmin(fn () => Coupon::where('code', $codes[1])->first()->isRedeemed()));

        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), ['invitation' => $invitation->id, 'hash' => sha1($existing->email)]);
        $this->actingAsInTenant($existing, $tenant)->get($url)->assertRedirect(route('dashboard'));

        $this->onAdmin(function () use ($codes, $existing, $tenant, $course) {
            $coupon = Coupon::where('code', $codes[1])->first();
            $this->assertTrue($coupon->isRedeemed());
            $this->assertSame($existing->id, $coupon->redeemed_by_user_id);
            $this->assertTrue(Entitlement::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->where('course_id', $course->id)->exists());
            $this->assertTrue(Coupon::where('code', $codes[0])->first()->isRedeemed());
        });
    }
}
