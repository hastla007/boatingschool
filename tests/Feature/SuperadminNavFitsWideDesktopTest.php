<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Superadmin-Menü passt auch ab 1536px nicht): Logo + 11
 * Menüpunkte + Nutzerblock brauchen mit Figtree rund 1400px, die Leiste
 * steckte aber in "max-w-7xl" (1216px Inhaltsbreite) -- auch oberhalb des
 * 2xl-Breakpoints überlagerte der Nutzerblock die letzten Menüpunkte bzw.
 * "Abmelden" rutschte aus dem Bild (per Playwright bei 1536/1600/1920px
 * nachgemessen: vorher -179px Abstand, jetzt positiv).
 */
class SuperadminNavFitsWideDesktopTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_desktop_navigation_uses_a_container_as_wide_as_its_breakpoint(): void
    {
        $superadmin = $this->createSuperAdmin(['name' => str_repeat('Sehr langer Name ', 5)]);

        $response = $this->actingAs($superadmin)->get(route('superadmin.dashboard'));

        $response->assertOk();
        $html = $response->getContent();
        $navStart = strpos($html, '<nav');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);

        $this->assertStringContainsString('max-w-screen-2xl mx-auto', $nav);
        $this->assertStringNotContainsString('max-w-7xl', $nav, 'Die Superadmin-Leiste darf nicht mehr auf 1280px begrenzt sein.');
        // Menüpunkte brechen nicht um, der Nutzerblock schrumpft nicht und
        // ein langer Name wird gekürzt, statt "Abmelden" zu verdrängen.
        $this->assertStringContainsString('hidden 2xl:flex gap-1 text-sm whitespace-nowrap', $nav);
        $this->assertStringContainsString('hidden 2xl:flex items-center gap-4 text-sm shrink-0 whitespace-nowrap', $nav);
        $this->assertMatchesRegularExpression('/<span class="[^"]*truncate max-w-\[10rem\][^"]*"[^>]*>\s*Sehr langer Name/', $nav);
    }
}
