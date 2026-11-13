<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantCourseDisabled;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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

    public function test_a_code_is_reserved_the_same_and_redeemed_only_on_acceptance_for_both(): void
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
        $this->assertSame('Einladung verschickt. Code "CODE" ist für diese Person reserviert und wird eingelöst, sobald sie die Einladung annimmt.', $statuses[0]);

        // Ungültiger Code: für beide derselbe Fehler, und es entsteht nichts
        // (frische Adressen, sonst greift die Tagesdrossel zuerst).
        [$otherNew, $otherExisting] = $this->addresses();
        foreach ([$otherNew, $otherExisting->email] as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => 'UNBEKANNT-0000'])
                ->assertSessionHasErrors(['code' => 'Dieser Code ist ungültig oder wurde bereits eingelöst.']);
        }
        $this->assertFalse($this->onAdmin(fn () => User::where('email', $otherNew)->exists()));
        $this->assertFalse($this->asTenant($tenant, fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $otherExisting->email)->exists()));

        // Ein schon reservierter Code kann nicht für eine andere Person
        // vergeben werden -- für beide Fälle gleich.
        foreach ([$otherNew, $otherExisting->email] as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => $codes[0]])
                ->assertSessionHasErrors(['code' => 'Dieser Code ist bereits für eine andere offene Einladung reserviert.']);
        }

        // Beide: vorgemerkt, noch nicht eingelöst; in der Coupon-Liste „reserviert für Einladung“.
        foreach ([$newEmail => $codes[0], $existing->email => $codes[1]] as $email => $code) {
            $invitation = $this->asTenant($tenant, fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $email)->firstOrFail());
            $this->assertSame($code, $invitation->coupon_code);
            $this->assertSame('Gast', $invitation->name);
            $this->assertFalse($this->onAdmin(fn () => Coupon::where('code', $code)->first()->isRedeemed()));
        }
        $couponPage = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=coupons')->assertOk();
        $this->assertSame(2, substr_count($couponPage->getContent(), 'reserviert für Einladung'));

        // Bestandskonto: eingelöst beim Annehmen.
        $invitation = $this->asTenant($tenant, fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $existing->email)->firstOrFail());
        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), ['invitation' => $invitation->id, 'hash' => sha1($existing->email)]);
        $this->actingAsInTenant($existing, $tenant)->get($url)->assertRedirect(route('dashboard'));

        // Neue Adresse: eingelöst beim Festlegen des Passworts (Einladungslink).
        $newUser = $this->onAdmin(fn () => User::where('email', $newEmail)->firstOrFail());
        $token = Password::broker('invites')->createToken($newUser);
        $this->app['auth']->forgetGuards();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'invite' => '1', 'token' => $token, 'email' => $newEmail,
            'password' => 'Ein-sicheres-Passwort-2026', 'password_confirmation' => 'Ein-sicheres-Passwort-2026', 'age_confirmed' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->onAdmin(function () use ($codes, $existing, $newUser, $tenant, $course, $newEmail) {
            foreach ([$codes[1] => $existing->id, $codes[0] => $newUser->id] as $code => $userId) {
                $coupon = Coupon::where('code', $code)->first();
                $this->assertTrue($coupon->isRedeemed());
                $this->assertSame($userId, $coupon->redeemed_by_user_id);
                $this->assertTrue(Entitlement::where('tenant_id', $tenant->id)->where('user_id', $userId)->where('course_id', $course->id)->exists());
            }
            $this->assertNotNull(TenantInvitation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', $newEmail)->value('accepted_at'));
            $this->assertNotNull(User::find($newUser->id)->age_confirmed_at);
        });
    }

    public function test_course_dependent_code_errors_appear_for_both_at_invite_time(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-ORACLE-OFF-'.Str::upper(Str::random(6));
        $this->createdCodes = [$code];
        $this->onAdmin(function () use ($code, $course, $tenant) {
            Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]);
            TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]);
        });
        [$newEmail, $existing] = $this->addresses();

        foreach ([$newEmail, $existing->email] as $email) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Gast', 'email' => $email, 'code' => $code])
                ->assertSessionHasErrors(['code' => 'Dieser Kurs ist aktuell nicht verfügbar.']);
        }
        $this->assertFalse($this->onAdmin(fn () => User::where('email', $newEmail)->exists()));
        $this->assertFalse($this->asTenant($tenant, fn () => TenantInvitation::where('tenant_id', $tenant->id)->exists()));
    }

    public function test_team_invites_answer_and_list_new_and_existing_addresses_the_same(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        [$newEmail, $existing] = $this->addresses();

        foreach ([0, 1] as $round) {
            $statuses = [];
            foreach ([$newEmail, $existing->email] as $email) {
                $statuses[] = $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Kollegin Test', 'email' => $email, 'role' => 'instructor'])
                    ->assertSessionHasNoErrors()->baseResponse->getSession()->get('status');
            }
            $this->assertSame($round === 0
                ? ['Einladung verschickt.', 'Einladung verschickt.']
                : array_fill(0, 2, 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.'), $statuses);
        }

        // Beide nur als „eingeladen“ mit Namen, keiner in der Team-Liste.
        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertEqualsCanonicalizing([$newEmail, $existing->email], collect($page->viewData('pendingTeamInvitations'))->pluck('email')->all());
        $this->assertSame(['Kollegin Test', 'Kollegin Test'], collect($page->viewData('pendingTeamInvitations'))->pluck('name')->all());
        $this->assertSame(['owner'], $page->viewData('team')->pluck('role')->all());
        $this->assertSame(2, substr_count($page->getContent(), 'wartet auf Bestätigung'));
    }
}
