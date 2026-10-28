<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R4-4: Teilnehmer-, Anmeldungen- und Coupon-Liste auf /admin
 * paginierten bisher ohne eigenen pageName -- Laravels Default dafür ist
 * bei jedem Paginator derselbe Query-String-Parameter "page". Da alle drei
 * Listen auf EINER Seite liegen (nur per Tab client-seitig umgeschaltet),
 * blätterte "nächste Seite" bei einer Liste automatisch auch die anderen
 * um, weil sie denselben Parameter auswerteten.
 */
class AdminDashboardPaginationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_paginating_the_participants_list_does_not_use_the_generic_shared_page_parameter(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        // 25 pro Seite (siehe AdminDashboardController) -- der 26. Teilnehmer
        // erscheint nur auf Seite 2.
        for ($i = 1; $i <= 26; $i++) {
            $this->createTenantUser($tenant, 'learner', ['display_name' => 'Teilnehmer Nr '.$i]);
        }

        // Das alte, geteilte "page"-Argument darf die Liste nicht mehr
        // beeinflussen -- ohne eigenen pageName hätte das vorher Seite 2
        // gezeigt.
        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?page=2');
        $response->assertOk();
        $response->assertDontSee('Teilnehmer Nr 26');

        // Der eigene, distinkte Parameter blättert die Liste tatsächlich um.
        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?participants_page=2');
        $response->assertOk();
        $response->assertSee('Teilnehmer Nr 26');

        $this->assertStringContainsString('participants_page=', $response->getContent());
    }

    /**
     * Bug-Review (Paginierung -- Teilnehmerliste nicht eindeutig sortiert):
     * die Liste sortierte bisher nur nach created_at -- mehrere Mitglieder
     * mit demselben Zeitstempel (z. B. durch einen gemeinsamen Import oder
     * einfach dieselbe Sekunde) hatten damit keine eindeutige Reihenfolge.
     * Ohne ein eindeutiges Tie-Break-Kriterium ist die Reihenfolge gleicher
     * Zeitstempel über verschiedene Abfragen (z. B. Seite 1 vs. Seite 2 der
     * Paginierung) hinweg nicht garantiert konsistent -- Teilnehmer können
     * doppelt erscheinen oder ausgelassen werden. Da sich das an der
     * konkreten Tie-Reihenfolge von Postgres nicht zuverlässig (ohne
     * Flakiness) demonstrieren lässt, prüft dieser Test direkt, dass die
     * tatsächlich ausgeführte Abfrage ein eindeutiges Tie-Break-Kriterium
     * (user_id) nach created_at enthält.
     */
    public function test_the_participants_list_query_breaks_ties_on_a_unique_column(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner');

        $orderByClauses = [];
        DB::listen(function ($query) use (&$orderByClauses) {
            if (str_contains($query->sql, 'from "tenant_user"') && str_contains($query->sql, 'order by')) {
                $orderByClauses[] = substr($query->sql, strpos($query->sql, 'order by'));
            }
        });

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
        $this->assertNotEmpty($orderByClauses, 'Erwartete mindestens eine sortierte tenant_user-Abfrage.');
        foreach ($orderByClauses as $orderBy) {
            $this->assertMatchesRegularExpression(
                '/"created_at" asc, "user_id" asc/',
                $orderBy,
                "Abfrage sortiert ohne eindeutiges Tie-Break-Kriterium: {$orderBy}"
            );
        }
    }
}
