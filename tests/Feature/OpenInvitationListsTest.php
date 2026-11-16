<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review: „Offene Einladungen“ (Teilnehmer und Team) zeigten jede nie
 * angenommene Einladung -- auch längst abgelaufene und solche an Adressen,
 * die inzwischen aktives Mitglied sind -- und ohne Obergrenze.
 */
class OpenInvitationListsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function invite(Tenant $tenant, User $by, string $email, string $role = 'learner', int $daysAgo = 0): void
    {
        $this->onAdmin(function () use ($tenant, $by, $email, $role, $daysAgo) {
            $invitation = TenantInvitation::create(['tenant_id' => $tenant->id, 'email' => $email, 'role' => $role, 'invited_by_user_id' => $by->id, 'name' => 'Name '.$email]);
            DB::table('tenant_invitation')->where('id', $invitation->id)->update(['created_at' => now()->subDays($daysAgo)->subMinutes(1)]);
        });
    }

    private function page(Tenant $tenant, User $owner)
    {
        return $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();
    }

    public function test_only_open_invitations_of_addresses_that_are_not_yet_members_are_listed(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $open = 'offen-'.Str::lower(Str::random(6)).'@example.test';
        $expired = 'abgelaufen-'.Str::lower(Str::random(6)).'@example.test';

        $this->invite($tenant, $owner, $open);
        $this->invite($tenant, $owner, $expired, daysAgo: 8);
        // Inzwischen aktive Teilnehmerin (z. B. selbst registriert): keine offene Einladung mehr.
        $this->invite($tenant, $owner, $learner->email);
        // Team: schon aktiver Ausbilder fällt heraus, eine aktive Teilnehmerin
        // mit Team-Einladung bleibt gelistet (die Einladung hebt die Rolle an).
        $this->invite($tenant, $owner, $instructor->email, 'instructor');
        $this->invite($tenant, $owner, $learner->email, 'instructor');
        $this->invite($tenant, $owner, $expired, 'admin', daysAgo: 8);

        $page = $this->page($tenant, $owner);
        $this->assertSame([$open], collect($page->viewData('openInvitations'))->pluck('email')->all());
        $this->assertSame(1, $page->viewData('openInvitationsTotal'));
        $this->assertSame([$learner->email], collect($page->viewData('pendingTeamInvitations'))->pluck('email')->all());
        $page->assertSee('Offene Einladungen (1)')->assertDontSee($expired);
    }

    public function test_the_team_list_shows_open_invitations_only_in_the_invitation_rows(): void
    {
        // Die Team-Liste enthält nur aktivierte Konten (nie „invited“) -- die
        // frühere Kennzeichnung „Einladung offen“ dort war toter Code.
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'instructor');

        $page = $this->page($tenant, $owner);
        $this->assertTrue($page->viewData('team')->every(fn ($m) => $m->user->status !== 'invited'));
        $this->assertSame(0, substr_count($page->getContent(), 'Einladung offen'));
    }

    public function test_the_list_is_limited_and_names_the_rest(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $limit = AdminDashboardController::OPEN_INVITATIONS_LIMIT;
        $this->onAdmin(function () use ($tenant, $owner, $limit) {
            $rows = [];
            for ($i = 0; $i < $limit + 2; $i++) {
                $rows[] = [
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'email' => "viele-{$i}-".Str::lower(Str::random(4)).'@example.test',
                    'role' => 'learner', 'invited_by_user_id' => $owner->id, 'created_at' => now()->subMinutes($i + 1),
                ];
            }
            DB::table('tenant_invitation')->insert($rows);
        });

        $page = $this->page($tenant, $owner);
        $this->assertCount($limit, $page->viewData('openInvitations'));
        $this->assertSame($limit + 2, $page->viewData('openInvitationsTotal'));
        $page->assertSee('Offene Einladungen ('.($limit + 2).')')->assertSee('… und 2 weitere offene Einladungen');
    }
}
