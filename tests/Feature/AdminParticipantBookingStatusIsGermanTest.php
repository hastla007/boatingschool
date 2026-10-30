<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Teilnehmer-Detail zeigt englischen Status): die Buchungsliste
 * in admin/participants/show gab den Entitlement-Status ("active",
 * "suspended") roh aus der DB aus, obwohl b77ddbb dafür bereits
 * StatusLabels::entitlementStatus() eingeführt hatte.
 */
class AdminParticipantBookingStatusIsGermanTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_booking_status_is_shown_as_a_german_label(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $active = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $suspended = $this->grantEntitlement($tenant, $learner, $this->existingCourse('UBI'));
        $this->onAdmin(fn () => Entitlement::where('id', $suspended->id)->update(['status' => 'suspended']));

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.participants.show', $learner));

        $response->assertOk();
        $response->assertSee('>gesperrt</span>', false);
        $response->assertSee('>aktiv</span>', false);
        $response->assertDontSee('>active</span>', false);
        $response->assertDontSee('>suspended</span>', false);
        // Die Formular-Logik arbeitet weiterhin mit dem rohen Enumwert.
        $response->assertSee('value="reactivate"', false);
        $this->assertNotNull($active);
    }
}
