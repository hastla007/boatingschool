<?php

namespace Tests\Feature;

use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LearningGroupService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Beitrittslink einer Lerngruppe (…/gruppe/<code>/) im Browser: Hinweisseite
 * statt 404, ohne Beitritt; Gruppe und Schule nur bei gültigem Code.
 */
class LearningGroupLinkPageTest extends TestCase
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

    private function tenant(string $name): Tenant
    {
        $tenant = $this->createTestTenant($name);
        $slug = strtolower($tenant->slug);
        $this->onAdmin(fn () => Tenant::where('id', $tenant->id)->update(['slug' => $slug]));
        $tenant->slug = $slug;

        return $tenant;
    }

    private function group(Tenant $tenant, User $owner, array $attributes = []): LearningGroup
    {
        return $this->onAdmin(fn () => LearningGroup::create($attributes + [
            'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => 'Segelverein Nord',
            'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addYear(),
            'join_code' => LearningGroupService::newJoinCode(),
        ]));
    }

    public function test_a_valid_link_shows_the_group_and_school_without_joining(): void
    {
        config(['mobile_api.store_url.ios' => 'https://apps.example.test/ios', 'mobile_api.store_url.android' => null]);
        $tenant = $this->tenant('Gruppenlink Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner);

        $page = $this->get("{$this->central}/gruppe/{$group->join_code}/")->assertOk()
            ->assertSee('Du wurdest in eine Lerngruppe eingeladen. Öffne diesen Link auf deinem Smartphone mit der Lern-App', false)
            ->assertSee('„Segelverein Nord“', false)->assertSee('Gruppenlink Schule')
            ->assertSee('https://apps.example.test/ios', false)->assertSee('App Store')->assertDontSee('Google Play')
            ->assertSee($group->join_code)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        // Pfad-Modus der Schule: dieselbe Seite.
        $this->get("{$this->central}/{$tenant->slug}/gruppe/{$group->join_code}")->assertOk()
            ->assertSee('„Segelverein Nord“', false);

        $this->assertSame(0, $this->onAdmin(fn () => $group->members()->count()), 'Die Seite tritt nicht bei.');
    }

    public function test_invalid_expired_and_personal_codes_get_the_same_neutral_page(): void
    {
        config(['mobile_api.store_url.ios' => null, 'mobile_api.store_url.android' => null]);
        $tenant = $this->tenant('Gruppenlink Neutral');
        $owner = $this->createTenantUser($tenant, 'learner');
        $expired = $this->group($tenant, $owner, ['name' => 'Abgelaufene Gruppe', 'valid_from' => now()->subMonth(), 'valid_until' => now()->subDay()]);
        $revoked = $this->group($tenant, $owner, ['name' => 'Beendete Gruppe', 'status' => 'revoked']);

        $codes = [$expired->join_code, $revoked->join_code, 'g'.str_repeat('0', 32), 'i'.str_repeat('a', 32), 'quatsch'];
        foreach ($codes as $code) {
            $this->get("{$this->central}/gruppe/{$code}/")->assertOk()
                ->assertSee('Du wurdest in eine Lerngruppe eingeladen.')
                ->assertDontSee('Abgelaufene Gruppe')->assertDontSee('Beendete Gruppe')->assertDontSee('Gruppenlink Neutral')
                ->assertDontSee('App Store')->assertDontSee('Google Play');
        }
    }

    public function test_another_schools_address_shows_no_details(): void
    {
        $tenant = $this->tenant('Gruppenlink Eigene');
        $other = $this->tenant('Gruppenlink Fremde');
        $owner = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner);

        $this->get("{$this->central}/{$other->slug}/gruppe/{$group->join_code}/")->assertOk()
            ->assertDontSee('Segelverein Nord')->assertSee('Du wurdest in eine Lerngruppe eingeladen.');
    }

    public function test_the_page_is_rate_limited(): void
    {
        $code = 'g'.str_repeat('1', 32);
        for ($i = 0; $i < 30; $i++) {
            $this->get("{$this->central}/gruppe/{$code}/")->assertOk();
        }
        $this->get("{$this->central}/gruppe/{$code}/")->assertStatus(429);
    }
}
