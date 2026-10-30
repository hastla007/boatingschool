<?php

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Nutzerseite zeigt leere Namensfelder):
 * first_name/last_name (Migration 000170) wurden bislang nur gesetzt, wenn
 * ein Superadmin einen Nutzer direkt anlegt/bearbeitet, eine Bootsschule
 * einen Admin anlegt, oder der Nutzer selbst sein Profil ausfüllt --
 * Selbstregistrierung und Einladung fragten nur ein einzelnes "Name"-Feld
 * ab und setzten ausschließlich display_name. Für praktisch jeden so
 * angelegten Nutzer blieben first_name/last_name dadurch leer, obwohl
 * display_name längst einen vollen Namen enthielt, und die
 * Superadmin-Bearbeitungsseite (dort als required geführt) zeigte diese
 * Felder entsprechend leer an.
 */
class UserNameSplitTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_split_display_name_splits_a_two_part_name(): void
    {
        $this->assertSame(['first_name' => 'Max', 'last_name' => 'Mustermann'], User::splitDisplayName('Max Mustermann'));
    }

    public function test_split_display_name_keeps_a_multi_part_first_name_together(): void
    {
        $this->assertSame(['first_name' => 'Max Erik', 'last_name' => 'Mustermann'], User::splitDisplayName('Max Erik Mustermann'));
    }

    public function test_split_display_name_leaves_last_name_null_for_a_single_word(): void
    {
        $this->assertSame(['first_name' => 'Admin', 'last_name' => null], User::splitDisplayName('Admin'));
    }

    public function test_registering_a_new_account_populates_first_and_last_name(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'neuer-nutzer-'.uniqid().'@example.test';

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Erika Musterfrau',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame('Erika', $user->first_name);
            $this->assertSame('Musterfrau', $user->last_name);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    public function test_inviting_a_participant_populates_first_and_last_name(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $email = 'einladung-namenstest-'.uniqid().'@example.test';

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Neuer Namenstest-Teilnehmer',
            'email' => $email,
        ]);

        $response->assertRedirect();

        // Bug-Review Testisolation: ein anderer Test (ParticipantInviteTest)
        // legt ebenfalls einen Teilnehmer per invite an -- ein eindeutiger
        // Name UND die zusätzliche eindeutige E-Mail-Adresse schließen
        // aus, dass dessen unscoped display_name-Abfrage ("Neuer
        // Teilnehmer") hier je nach Testreihenfolge den falschen Nutzer
        // zurückliefert.
        $this->onAdmin(function () use ($email, $tenant) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame('Neuer', $user->first_name);
            $this->assertSame('Namenstest-Teilnehmer', $user->last_name);

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
            $user->delete();
        });
    }

    public function test_superadmin_user_page_prefills_a_guessed_name_when_none_is_stored(): void
    {
        $superadmin = $this->createSuperAdmin();
        $learner = $this->onAdmin(fn () => User::create([
            'display_name' => 'Gast Lernender',
            'email' => 'gast-lernender-'.uniqid().'@example.test',
            'password' => 'irrelevant',
            'status' => 'active',
        ]));

        $response = $this->actingAs($superadmin)->get(route('superadmin.users.show', $learner));

        $response->assertOk();
        $response->assertSee('value="Gast"', false);
        $response->assertSee('value="Lernender"', false);
    }

    public function test_superadmin_can_save_an_account_with_a_one_word_name(): void
    {
        $superadmin = $this->createSuperAdmin();
        $learner = $this->onAdmin(fn () => User::create([
            'display_name' => 'Cher',
            'email' => 'cher-'.uniqid().'@example.test',
            'password' => 'irrelevant',
            'status' => 'active',
        ]));

        $this->actingAs($superadmin)->patch(route('superadmin.users.update', $learner), [
            'first_name' => 'Cher',
            'last_name' => '',
            'email' => $learner->email,
            'status' => 'suspended',
        ])->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($learner) {
            $this->assertSame('suspended', $learner->fresh()->status);
            $this->assertSame('Cher', $learner->fresh()->display_name);
        });
    }
}
