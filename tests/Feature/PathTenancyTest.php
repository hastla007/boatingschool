<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschule unter CENTRAL_DOMAIN/<slug> (Standard, ohne Wildcard-DNS):
 * Auflösung über den Pfad, Links mit Präfix, eigene Sitzung je Schule.
 */
class PathTenancyTest extends TestCase
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

    /** Slugs sind immer kleingeschrieben (Validierung beim Anlegen). */
    private function tenant(string $name = 'Pfad Schule'): Tenant
    {
        $tenant = $this->createTestTenant($name);
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));
        $tenant->slug = $slug;

        return $tenant;
    }

    public function test_the_school_is_reachable_under_its_path_with_prefixed_links(): void
    {
        $tenant = $this->tenant('Pfad Schule');
        $base = "{$this->central}/{$tenant->slug}";

        $this->get($base)->assertRedirect("{$base}/");
        $page = $this->get("{$base}/login")->assertOk();
        $page->assertSee('Pfad Schule');
        $page->assertSee('action="'.$base.'/login"', false);
        $page->assertDontSee("/{$tenant->slug}/build/", false);

        // Eigene Sitzung je Schule: Cookie-Name mit Slug, Cookies nur für den Pfad
        // (das Sitzungs-Cookie selbst setzt der array-Treiber der Tests nicht).
        $this->assertStringEndsWith('_'.str_replace('-', '_', $tenant->slug), config('session.cookie'));
        $xsrf = collect($page->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        $this->assertSame("/{$tenant->slug}", $xsrf->getPath());

        // Danach auf der zentralen Domain wieder die normalen Einstellungen.
        $central = $this->get("{$this->central}/login")->assertOk();
        $this->assertSame('/', collect($central->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN')->getPath());
    }

    public function test_login_and_learning_pages_work_under_the_path(): void
    {
        $tenant = $this->tenant();
        $learner = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!')]);
        $base = "{$this->central}/{$tenant->slug}";

        $this->post("{$base}/login", ['email' => $learner->email, 'password' => 'geheim123!'])->assertRedirect("{$base}/dashboard");
        $this->assertAuthenticatedAs($learner);

        $this->get("{$base}/dashboard")->assertOk()->assertSee("{$base}/courses", false);
    }

    public function test_superadmin_area_and_unknown_paths_stay_on_the_central_root(): void
    {
        $tenant = $this->tenant();
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get("{$this->central}/{$tenant->slug}/superadmin")->assertNotFound();
        $this->actingAs($superadmin)->get("{$this->central}/superadmin")->assertOk();
        $this->get("{$this->central}/gibt-es-nicht/login")->assertNotFound();
    }

    public function test_the_school_picker_and_mail_links_use_the_path(): void
    {
        $tenant = $this->tenant('Picker Schule');
        $this->flushSession();
        $this->get("{$this->central}/")->assertSee("{$this->central}/{$tenant->slug}", false)->assertSee(config('app.central_domain')."/{$tenant->slug}");

        Notification::fake();
        $user = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);
        $this->actingAs($user)->post("{$this->central}/{$tenant->slug}/email/verification-notification")->assertRedirect();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith("{$this->central}/{$tenant->slug}/verify-email/", $url);
        $this->actingAs($user)->get($url)->assertRedirect();
        $this->assertNotNull($this->onAdmin(fn () => User::find($user->id)->email_verified_at), 'signierter Link gilt unter dem Pfad');
    }

    public function test_route_names_cannot_be_used_as_slugs(): void
    {
        $superadmin = $this->createSuperAdmin();
        foreach (['login', 'courses', 'storage'] as $slug) {
            $this->actingAs($superadmin)->post('/superadmin/tenants', [
                'slug' => $slug, 'name' => 'X', 'status' => 'active',
                'admin_first_name' => 'A', 'admin_last_name' => 'B', 'admin_email' => "a-{$slug}@example.test",
            ])->assertSessionHasErrors('slug');
        }
    }
}
