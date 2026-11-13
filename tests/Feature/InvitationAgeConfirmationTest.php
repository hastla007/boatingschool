<?php

namespace Tests\Feature;

use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AgeConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Altersbestätigung für eingeladene Personen: einmalig beim Festlegen des
 * Passworts (neues Konto) bzw. beim Annehmen der Einladung (Bestandskonto
 * ohne Bestätigung). Dazu: ein neu eingeladenes Team-Mitglied nimmt die
 * Einladung mit dem ersten Passwort an.
 */
class InvitationAgeConfirmationTest extends TestCase
{
    use InteractsWithTenants;

    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach (User::whereIn('email', $this->emails)->get() as $user) {
                DB::table('account_setup_tokens')->where('email', $user->email)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->delete();
            }
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function setPassword($tenant, string $email, string $token, array $extra = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', $extra + [
            'invite' => '1', 'token' => $token, 'email' => $email,
            'password' => 'Ein-sicheres-Passwort-2026', 'password_confirmation' => 'Ein-sicheres-Passwort-2026',
        ]);
    }

    public function test_a_newly_invited_person_confirms_the_age_once_when_setting_the_password(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $email = $this->emails[] = 'alter-neu-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Person', 'email' => $email])->assertSessionHasNoErrors();
        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $token = Password::broker('invites')->createToken($user);

        $this->app['auth']->forgetGuards();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/reset-password/'.$token.'?email='.urlencode($email).'&invite=1')
            ->assertOk()->assertSee(AgeConfirmation::LABEL)->assertSee('name="age_confirmed"', false);

        $this->setPassword($tenant, $email, $token)->assertSessionHasErrors(['age_confirmed' => AgeConfirmation::MESSAGE]);
        $this->assertSame('invited', $this->onAdmin(fn () => User::find($user->id)->status));

        $this->setPassword($tenant, $email, $token, ['age_confirmed' => '1'])->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $user = $this->onAdmin(fn () => User::find($user->id));
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->age_confirmed_at);
        $this->assertNotNull($this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $email)->value('accepted_at')));
    }

    public function test_an_invalid_link_does_not_reveal_an_invited_account_through_the_age_question(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $email = $this->emails[] = 'alter-orakel-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Person', 'email' => $email]);

        $this->setPassword($tenant, $email, 'falscher-token')->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('age_confirmed');
    }

    public function test_an_existing_account_without_confirmation_confirms_before_accepting(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner', ['age_confirmed_at' => null]);
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'Bestand', 'email' => $existing->email])->assertSessionHasNoErrors();
        $invitation = $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $existing->email)->firstOrFail());
        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), ['invitation' => $invitation->id, 'hash' => sha1($existing->email)]);

        $this->actingAsInTenant($existing, $tenant)->get($url)->assertOk()->assertSee(AgeConfirmation::LABEL)->assertSee('Einladung annehmen');
        $isMember = fn () => $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->exists());
        $this->assertFalse($isMember());

        $this->actingAsInTenant($existing, $tenant)->from($url)->post($url)->assertSessionHasErrors(['age_confirmed' => AgeConfirmation::MESSAGE]);
        $this->assertFalse($isMember());

        $this->actingAsInTenant($existing, $tenant)->post($url, ['age_confirmed' => '1'])->assertRedirect(route('dashboard'));
        $this->assertTrue($isMember());
        $this->assertNotNull($this->onAdmin(fn () => User::find($existing->id)->age_confirmed_at));
    }

    public function test_a_newly_invited_team_member_accepts_with_the_first_password_and_then_appears_in_the_team(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $email = $this->emails[] = 'team-neu-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Ina Ausbilder', 'email' => $email, 'role' => 'instructor'])->assertSessionHasNoErrors();

        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertSame([$email], collect($page->viewData('pendingTeamInvitations'))->pluck('email')->all());
        $this->assertNotContains($email, $page->viewData('team')->map(fn ($m) => $m->user->email)->all());

        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->setPassword($tenant, $email, Password::broker('invites')->createToken($user), ['age_confirmed' => '1'])->assertSessionHasNoErrors();

        $page = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
        $this->assertSame([], collect($page->viewData('pendingTeamInvitations'))->pluck('email')->all());
        $this->assertContains($email, $page->viewData('team')->map(fn ($m) => $m->user->email)->all());
    }

    public function test_a_team_invitation_accepted_by_a_learner_of_the_school_raises_the_role(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $invitation = $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id, 'email' => $learner->email, 'role' => 'instructor', 'invited_by_user_id' => $owner->id,
        ]));
        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), ['invitation' => $invitation->id, 'hash' => sha1($learner->email)]);

        $this->actingAsInTenant($learner, $tenant)->get($url)->assertRedirect(route('dashboard'));

        $this->assertSame('instructor', $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('role')));
    }
}
