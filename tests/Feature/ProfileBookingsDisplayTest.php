<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Profil/Kursübersicht): Freischaltdaten wurden in UTC statt
 * deutscher Zeit angezeigt (kurz nach Mitternacht der Vortag), ein per
 * Verlängerungscode eingelöster Zugang (coupon_renewal) erschien als "Von
 * deiner Bootsschule freigeschaltet", und der Status eines Zugangs fehlte
 * in "Deine Buchungen" völlig.
 */
class ProfileBookingsDisplayTest extends TestCase
{
    use InteractsWithTenants;

    public function test_profile_bookings_show_german_dates_renewal_codes_and_status(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $see = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SBF-SEE'));
        $src = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));

        // 23:30 UTC = 00:30 Uhr deutscher Zeit (Sommerzeit) am Folgetag.
        $this->onAdmin(function () use ($see, $src) {
            Entitlement::whereKey($see->id)->update([
                'source_type' => 'coupon_renewal',
                'source_reference' => 'VERL-TEST-123',
                'valid_from' => Carbon::parse('2026-06-10 23:30:00', 'UTC'),
            ]);
            Entitlement::whereKey($src->id)->update(['status' => 'suspended']);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile');

        $response->assertOk()
            ->assertSee('Code VERL-TEST-123')
            ->assertSee('11.06.2026')
            ->assertDontSee('10.06.2026')
            ->assertSee('aktiv')
            ->assertSee('gesperrt')
            ->assertDontSee('text-xs text-gray-400', false);
    }

    public function test_course_overview_shows_the_unlock_date_in_german_time(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SBF-SEE'));
        $this->onAdmin(fn () => Entitlement::whereKey($entitlement->id)->update(['valid_from' => Carbon::parse('2026-06-10 23:30:00', 'UTC')]));

        $this->actingAsInTenant($learner, $tenant)->get('/courses')
            ->assertOk()
            ->assertSee('Freigeschaltet am 11.06.2026');
    }
}
