<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\ProductPurchase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Kürzlich gebuchte Kurse & Produkte" auf dem Bootsschul-Dashboard
 * (AdminDashboardController): ein Info-Hinweis über alle Teilnehmer der
 * Bootsschule hinweg, damit neue Buchungen nicht untergehen -- anders als
 * die Benachrichtigungsbox auf der Teilnehmer-Detailseite, die nur dort
 * anstehende Aktionen zeigt (siehe ParticipantDetailTest).
 */
class AdminDashboardRecentBookingsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_dashboard_shows_a_recently_booked_course_and_product(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Erika Musterfrau']);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_reference' => 'admin:'.$admin->id,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Kürzlich gebuchte Kurse');
        $response->assertSeeInOrder(['Erika Musterfrau', $course->name]);
        $response->assertSeeInOrder(['Erika Musterfrau', $product->name]);
    }

    public function test_dashboard_shows_bundled_options_under_the_booked_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Erika Musterfrau']);
        $course = $this->existingCourse('SRC');
        $willkommensbox = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $kleinePraxis = $this->onAdmin(fn () => Product::where('code', 'PRAXISEINHEIT-KLEIN')->firstOrFail());
        $code = 'E2E-DASHBOARD-OPTIONS';
        $this->createdCodes[] = $code;
        $this->onAdmin(function () use ($code, $course, $willkommensbox, $kleinePraxis, $tenant) {
            $coupon = Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]);
            $coupon->optionProducts()->attach([$willkommensbox->id, $kleinePraxis->id]);
        });
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        // Die Optionen erscheinen als Zusatzzeile unter dem Kurs statt als
        // eigenständige Buchung (wie auf der Teilnehmer-Detailseite).
        $response->assertSeeInOrder(['Erika Musterfrau', $course->name, $willkommensbox->name, $kleinePraxis->name]);
        $response->assertSeeText('+ '.$willkommensbox->name.', '.$kleinePraxis->name);
    }

    public function test_dashboard_does_not_show_bookings_older_than_seven_days(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Alter Fall']);
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'manual',
            'valid_from' => now()->subDays(10),
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        // "Alter Fall" taucht als Name trotzdem im Teilnehmer-Tab derselben
        // Seite auf -- hier zählt nur, dass die Buchungsliste selbst den
        // Leerzustand zeigt statt den alten Eintrag.
        $response->assertSee('Keine Buchungen in den letzten 7 Tagen.');
        $recentBookingsSection = Str::between($response->getContent(), 'Kürzlich gebuchte Kurse', 'Letzte Aktivitäten');
        $this->assertStringNotContainsString('Alter Fall', $recentBookingsSection);
    }

    public function test_dashboard_shows_a_hint_without_any_recent_bookings(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Keine Buchungen in den letzten 7 Tagen.');
    }
}
