<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** „Als Nutzer ansehen“: Einmal-Link, nur lesend, Banner, Protokoll, Ablauf. */
class ImpersonationTest extends TestCase
{
    use InteractsWithTenants;

    private string $central;

    protected function setUp(): void
    {
        parent::setUp();
        $this->central = 'http://'.config('app.central_domain');
    }

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        $tenant = $this->createTestTenant('Ansicht Schule');
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));
        $tenant->slug = $slug;

        return $tenant;
    }

    private function actions(User $user): array
    {
        return $this->onAdmin(fn () => AuditLog::where('entity_id', $user->id)->where('action', 'like', 'superadmin.impersonate.%')->orderBy('created_at')->pluck('action')->all());
    }

    public function test_superadmin_views_the_platform_as_a_learner_read_only_and_ends_it(): void
    {
        $tenant = $this->tenant();
        $superadmin = $this->createSuperAdmin();
        $learner = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!')]);
        $base = "{$this->central}/{$tenant->slug}";

        $this->actingAs($superadmin)->get("{$this->central}/superadmin/users/{$learner->id}")->assertSee('Als Nutzer ansehen');
        $start = $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->assertOk();
        $link = $start->viewData('url');
        $start->assertSee('http-equiv="refresh"', false)->assertSee('Support-Ansicht öffnen');
        $this->assertStringStartsWith("{$base}/impersonate/", $link);

        auth()->logout();
        $this->flushSession();
        $this->get($link)->assertRedirect("{$base}/dashboard");
        $this->assertAuthenticatedAs($learner);
        $this->assertSame($superadmin->id, session(Impersonation::SESSION_KEY)['impersonator_id']);
        // Die Support-Ansicht ist keine Anmeldung der Person selbst (Löschfrist für inaktive Konten).
        $this->assertNull($this->onAdmin(fn () => User::find($learner->id))->last_login_at);

        $page = $this->get("{$base}/dashboard")->assertOk();
        $page->assertSee('Support-Ansicht:')->assertSee($learner->email)->assertSee('Support-Ansicht beenden');

        // Nur lesend: Änderungen werden abgelehnt und protokolliert.
        $this->patch("{$base}/profile", ['name' => 'Geändert', 'email' => $learner->email])->assertSessionHas('status', 'In der Support-Ansicht sind Änderungen gesperrt.');
        $this->assertNotSame('Geändert', $this->onAdmin(fn () => User::find($learner->id))->display_name);

        // Der Link gilt nur einmal.
        $this->get($link)->assertForbidden();

        $this->post("{$base}/impersonate/stop")->assertOk()->assertSee('Support-Ansicht beendet')->assertSee("{$this->central}/superadmin/users/{$learner->id}");
        $this->assertGuest();
        $this->assertSame(['superadmin.impersonate.start', 'superadmin.impersonate.blocked', 'superadmin.impersonate.stop'], $this->actions($learner));
    }

    public function test_superadmins_inactive_accounts_and_foreign_schools_are_refused(): void
    {
        $tenant = $this->tenant();
        $other = $this->createTestTenant('Andere Schule');
        $superadmin = $this->createSuperAdmin();
        $otherSuperadmin = $this->createSuperAdmin();
        $learner = $this->createTenantUser($tenant, 'learner');
        $suspended = $this->createTenantUser($tenant, 'learner', ['status' => 'suspended']);

        $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$otherSuperadmin->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->assertSessionHasErrors('impersonate');
        $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$suspended->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->assertSessionHasErrors('impersonate');
        $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$learner->id}/impersonate/{$other->id}", ['reason' => 'Support-Anfrage im Test'])->assertSessionHasErrors('impersonate');

        // Link einer Schule unter einer anderen Schule eingelöst: abgelehnt.
        $link = $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->viewData('url');
        $otherSlug = strtolower($other->slug);
        $this->onAdmin(fn () => Tenant::where('id', $other->id)->update(['slug' => $otherSlug]));
        auth()->logout();
        $this->get(str_replace("/{$tenant->slug}/", "/{$otherSlug}/", $link))->assertForbidden();
        $this->assertGuest();

        // Schul-Admins können keine Ansicht starten.
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->actingAsInTenant($admin, $tenant)->post("{$this->central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->assertForbidden();
    }

    public function test_the_view_ends_automatically_after_the_time_limit(): void
    {
        $tenant = $this->tenant();
        $superadmin = $this->createSuperAdmin();
        $learner = $this->createTenantUser($tenant, 'learner');
        $base = "{$this->central}/{$tenant->slug}";

        $link = $this->actingAs($superadmin)->post("{$this->central}/superadmin/users/{$learner->id}/impersonate/{$tenant->id}", ['reason' => 'Support-Anfrage im Test'])->viewData('url');
        auth()->logout();
        $this->get($link);
        $this->assertAuthenticatedAs($learner);

        $this->travel(Impersonation::MAX_MINUTES + 1)->minutes();
        $this->get("{$base}/dashboard")->assertRedirect("{$this->central}/superadmin");
        $this->assertGuest();
        $this->assertContains('superadmin.impersonate.expired', $this->actions($learner));
    }
}
