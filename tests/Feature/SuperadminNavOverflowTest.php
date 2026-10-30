<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Menü läuft bei 1024-1439px über, "Abmelden" nicht
 * sichtbar): siehe layouts/superadmin.blade.php. Der Desktop-Navigation
 * (11 Sektionen + Nutzername + "Abmelden") reichte "hidden md:flex" (768px)
 * nicht annähernd aus -- zwischen 768px und rund 1440px lief die Zeile über
 * und schob "Abmelden" aus dem sichtbaren Bereich. Der Schwellenwert liegt
 * jetzt bei "2xl" (1536px); darunter greift durchgehend das Hamburger-Menü.
 */
class SuperadminNavOverflowTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_desktop_navigation_only_shows_from_the_2xl_breakpoint(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get(route('superadmin.dashboard'));

        $response->assertOk();
        $response->assertSee('hidden 2xl:flex gap-1 text-sm', false);
        $response->assertSee('hidden 2xl:flex items-center gap-4 text-sm', false);
        $response->assertDontSee('hidden md:flex gap-1 text-sm', false);
        $response->assertDontSee('hidden md:flex items-center gap-4 text-sm', false);
    }

    public function test_the_hamburger_menu_covers_everything_below_the_2xl_breakpoint(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get(route('superadmin.dashboard'));

        $response->assertOk();
        $response->assertSee('flex items-center 2xl:hidden', false);
        $response->assertSee('hidden 2xl:hidden border-t border-slate-800', false);
        $response->assertDontSee('flex items-center md:hidden', false);
    }
}
