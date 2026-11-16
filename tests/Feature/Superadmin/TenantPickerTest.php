<?php

namespace Tests\Feature\Superadmin;

use App\Models\Coupon;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantPicker;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Schulauswahl im Superadmin-Bereich: Suche (superadmin.tenants.search) statt
 * Auswahllisten aller Schulen, ohne JavaScript Kürzel (Slug) im Begleitfeld
 * tenant_slug (App\Support\TenantPicker).
 */
class TenantPickerTest extends TestCase
{
    use InteractsWithTenants;

    public function test_search_is_only_for_superadmins(): void
    {
        $this->getJson('/superadmin/tenants/search?q=te')->assertUnauthorized();

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->actingAs($owner)->getJson('/superadmin/tenants/search?q=te')->assertForbidden();
    }

    public function test_search_matches_name_and_slug_and_returns_only_the_needed_fields(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = 'Pickerschule '.Str::lower(Str::random(6));
        $tenant = $this->createTestTenant($marker);
        $this->createTestTenant('Andere Schule');

        $byName = $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q='.urlencode($marker))->assertOk()->json('data');
        $this->assertCount(1, $byName);
        $this->assertSame(['id', 'name', 'slug', 'status', 'status_label'], array_keys($byName[0]));
        $this->assertSame([$tenant->id, $marker, $tenant->slug, 'active'], [$byName[0]['id'], $byName[0]['name'], $byName[0]['slug'], $byName[0]['status']]);

        $bySlug = $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q='.urlencode(strtoupper(substr($tenant->slug, -8))))->assertOk()->json('data');
        $this->assertSame([$tenant->id], array_column($bySlug, 'id'));

        // Ein Zeichen: noch keine Suche; Platzhalter werden wörtlich gesucht.
        $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q=p')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q=%25%25')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q[]=x')->assertOk();
    }

    public function test_search_returns_at_most_twenty_and_without_a_term_the_first_by_name(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = 'Limitschule '.Str::lower(Str::random(6));
        for ($i = 0; $i < TenantPicker::SEARCH_LIMIT + 1; $i++) {
            $this->createTestTenant($marker.' '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $results = $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q='.urlencode($marker))->assertOk()->json('data');
        $this->assertCount(TenantPicker::SEARCH_LIMIT, $results);
        $this->assertSame($marker.' 00', $results[0]['name']);
        $this->assertSame($marker.' 19', $results[19]['name']);

        $first = $this->actingAs($superadmin)->getJson('/superadmin/tenants/search?q=')->assertOk()->json('data');
        $expected = $this->onAdmin(fn () => Tenant::orderByRaw('lower(name)')->orderBy('id')->limit(TenantPicker::SEARCH_LIMIT)->pluck('id')->all());
        $this->assertSame($expected, array_column($first, 'id'));
        $this->assertLessThanOrEqual(TenantPicker::SEARCH_LIMIT, count($first));
    }

    public function test_pages_render_the_picker_instead_of_a_list_of_all_schools(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Nicht in der Liste '.Str::lower(Str::random(6)));
        $user = $this->createTenantUser($this->createTestTenant(), 'learner');

        foreach (['/superadmin/users/create', "/superadmin/users/{$user->id}", '/superadmin/audit', '/superadmin/coupons'] as $url) {
            $response = $this->actingAs($superadmin)->get($url)->assertOk();
            $response->assertSee('data-tenant-picker', false);
            $response->assertDontSee('<option value="'.$tenant->id.'"', false);
        }

        // Filter per tenant_id (bisherige Links) zeigt die Schule im Suchfeld.
        $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_id='.$tenant->id)->assertOk()
            ->assertSee('value="'.$tenant->id.'"', false)->assertSee('value="'.e($tenant->name).'"', false);
    }

    public function test_membership_form_accepts_the_school_slug_without_javascript(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Slugschule');
        $user = $this->createTenantUser($this->createTestTenant(), 'learner');

        $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/memberships", [
            'tenant_id' => '',
            'tenant_slug' => ' '.strtoupper($tenant->slug).' ',
            'role' => 'instructor',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->onAdmin(fn () => $this->assertSame('instructor', TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role')));
    }

    public function test_membership_form_rejects_unknown_slug_and_an_emptied_field(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($this->createTestTenant(), 'learner');
        $url = "/superadmin/users/{$user->id}/memberships";

        $this->actingAs($superadmin)->from("/superadmin/users/{$user->id}")
            ->post($url, ['tenant_id' => '', 'tenant_slug' => 'gibt-es-nicht-'.Str::random(6), 'role' => 'learner'])
            ->assertSessionHasErrors(['tenant_id' => TenantPicker::NOT_FOUND]);

        // Feld geleert, das versteckte Feld trägt noch die alte ID: keine Auswahl.
        $this->actingAs($superadmin)->post($url, ['tenant_id' => $tenant->id, 'tenant_slug' => '', 'role' => 'learner'])
            ->assertSessionHasErrors(['tenant_id' => TenantPicker::REQUIRED]);

        // Auswahl per Suche: ID und angezeigter Name passen zusammen.
        $this->actingAs($superadmin)->post($url, ['tenant_id' => $tenant->id, 'tenant_slug' => $tenant->name, 'role' => 'learner'])
            ->assertSessionHasNoErrors();
        $this->onAdmin(fn () => $this->assertTrue(TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()));
    }

    public function test_audit_filter_resolves_a_slug_and_keeps_tenant_and_platform_params(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Protokollschule');

        $this->actingAs($superadmin)->get('/superadmin/audit?tenant_slug='.$tenant->slug)->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['tenant'] === $tenant->id);
        $this->actingAs($superadmin)->get('/superadmin/audit?tenant='.$tenant->id)->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['tenant'] === $tenant->id);
        $this->actingAs($superadmin)->get('/superadmin/audit?tenant=platform')->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['tenant'] === 'platform');
        $this->actingAs($superadmin)->get('/superadmin/audit?platform_only=1')->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['tenant'] === 'platform');
        $this->actingAs($superadmin)->get('/superadmin/audit?tenant_slug=gibt-es-nicht')->assertOk()
            ->assertSee('Der Schulfilter wurde nicht angewendet.');

        // Codes: derselbe Filter, Export/Sperren übernehmen die aufgelöste ID.
        $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_slug='.$tenant->slug)->assertOk()
            ->assertViewHas('filters', fn (array $filters) => ($filters['tenant_id'] ?? null) === $tenant->id)
            ->assertSee('name="tenant_id" value="'.$tenant->id.'"', false);
        $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_id='.$tenant->id.'&tenant_slug=')->assertOk()
            ->assertViewHas('filters', fn (array $filters) => ! isset($filters['tenant_id']));
    }

    /**
     * Ein nicht auflösbarer Schulfilter (z. B. Teil eines Namens, ohne einen
     * Treffer zu wählen) zeigt keine Codes statt aller Schulen -- und
     * „alle gefilterten Codes“ sperren bzw. exportieren wirkt dann nicht auf
     * den Bestand aller Schulen, sondern wird abgelehnt.
     */
    public function test_unresolved_coupon_school_filter_neither_lists_nor_voids_codes_of_all_schools(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenantA = $this->createTestTenant('Codeschule A');
        $tenantB = $this->createTestTenant('Codeschule B');
        $codeA = 'PICK-A-'.Str::upper(Str::random(8));
        $codeB = 'PICK-B-'.Str::upper(Str::random(8));
        $course = $this->existingCourse('SRC');
        [$couponA, $couponB] = $this->onAdmin(fn () => [
            Coupon::create(['code' => $codeA, 'course_id' => $course->id, 'tenant_id' => $tenantA->id]),
            Coupon::create(['code' => $codeB, 'course_id' => $course->id, 'tenant_id' => $tenantB->id]),
        ]);

        $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_id=&tenant_slug=Codeschule')->assertOk()
            ->assertSee(TenantPicker::NOT_FOUND)
            ->assertDontSee($codeA)->assertDontSee($codeB)
            ->assertSee('name="tenant_slug" value="Codeschule"', false);

        foreach (['/superadmin/coupons/void', '/superadmin/coupons/export'] as $url) {
            $this->actingAs($superadmin)->from('/superadmin/coupons')
                ->post($url, ['select_all' => '1', 'tenant_id' => '', 'tenant_slug' => 'Codeschule'])
                ->assertSessionHasErrors(['tenant_id' => TenantPicker::NOT_FOUND]);
        }
        $this->onAdmin(function () use ($couponA, $couponB) {
            $this->assertNull($couponA->fresh()->voided_at);
            $this->assertNull($couponB->fresh()->voided_at);
        });

        // Auflösbarer Text: nur die Codes dieser Schule.
        $this->actingAs($superadmin)->post('/superadmin/coupons/void', ['select_all' => '1', 'tenant_id' => '', 'tenant_slug' => $tenantA->slug])
            ->assertSessionHasNoErrors();
        $this->onAdmin(function () use ($couponA, $couponB) {
            $this->assertNotNull($couponA->fresh()->voided_at);
            $this->assertNull($couponB->fresh()->voided_at);
        });
    }
}
