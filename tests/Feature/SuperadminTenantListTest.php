<?php

namespace Tests\Feature;

use App\Http\Controllers\Superadmin\TenantController;
use App\Models\Tenant;
use App\Models\TenantBranding;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Schulliste des Betreibers (/superadmin/tenants): seitenweise statt aller
 * Schulen auf einer Seite, Suche in Name, Slug und eigener Domain, Filter
 * nach Status, sortiert nach Name.
 */
class SuperadminTenantListTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_list_is_paginated_sorted_by_name_and_searchable(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = 'Seitenliste '.Str::lower(Str::random(6));
        // Absichtlich nicht in Namensreihenfolge angelegt.
        foreach (array_reverse(range(1, TenantController::PER_PAGE + 5)) as $i) {
            $this->createTestTenant(sprintf('%s %03d', $marker, $i));
        }

        $first = $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => $marker]))->assertOk();
        $first->assertSeeInOrder([$marker.' 001', $marker.' 002', $marker.' 050'])
            ->assertDontSee($marker.' 051')
            ->assertSee((TenantController::PER_PAGE + 5).' Bootsschulen, 1&ndash;50 angezeigt', false)
            ->assertSee('Weiter');
        // Seitenlinks behalten Suche und Filter.
        $first->assertSee('q='.rawurlencode($marker).'&amp;page=2', false);

        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => $marker, 'page' => 2]))->assertOk()
            ->assertSeeInOrder([$marker.' 051', $marker.' 055'])->assertDontSee($marker.' 050');

        // Ohne Suche ebenfalls höchstens eine Seite.
        $all = $this->actingAs($superadmin)->get(route('superadmin.tenants.index'))->assertOk();
        $this->assertLessThanOrEqual(TenantController::PER_PAGE, $all->viewData('tenants')->count());
        $this->assertGreaterThanOrEqual(TenantController::PER_PAGE + 5, $all->viewData('tenants')->total());
    }

    public function test_search_finds_slug_and_custom_domain_and_filters_by_status(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = Str::lower(Str::random(8));
        $bySlug = $this->createTestTenant('Slugschule '.$marker);
        $byDomain = $this->createTestTenant('Domainschule');
        $suspended = $this->createTestTenant('Gesperrte Schule '.$marker);
        $this->onAdmin(function () use ($byDomain, $suspended, $marker) {
            TenantBranding::where('tenant_id', $byDomain->id)->update(['custom_domain' => 'kurse.'.$marker.'.example']);
            Tenant::whereKey($suspended->id)->update(['status' => 'suspended']);
        });

        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => strtoupper($bySlug->slug)]))->assertOk()
            ->assertSee($bySlug->name)->assertDontSee('Domainschule')->assertSee('1 Bootsschule,', false);
        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => 'kurse.'.$marker]))->assertOk()
            ->assertSee('Domainschule')->assertDontSee($bySlug->name);

        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => $marker, 'status' => 'suspended']))->assertOk()
            ->assertSee($suspended->name)->assertDontSee($bySlug->name)
            ->assertSee('<option value="suspended" selected>', false)->assertSee('Filter zurücksetzen');
        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => $marker, 'status' => 'active']))->assertOk()
            ->assertSee($bySlug->name)->assertDontSee($suspended->name);

        // Unbekannter Status und Array-Werte werden ignoriert, LIKE-Platzhalter wörtlich gesucht.
        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => $marker, 'status' => 'kaputt']))->assertOk()
            ->assertSee($bySlug->name)->assertSee($suspended->name);
        $this->actingAs($superadmin)->get('/superadmin/tenants?q[]=x&status[]=active')->assertOk();
        $this->actingAs($superadmin)->get(route('superadmin.tenants.index', ['q' => '%_%_%_%_%_%_%_%_%_%']))->assertOk()
            ->assertSee('Keine Bootsschulen gefunden.');
    }

    public function test_the_search_form_is_labelled(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get(route('superadmin.tenants.index'))->assertOk()
            ->assertSee('<label for="tenant_search"', false)->assertSee('id="tenant_search" name="q"', false)
            ->assertSee('<label for="tenant_status"', false)
            ->assertDontSee('Filter zurücksetzen');
    }
}
