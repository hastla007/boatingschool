<?php

namespace Tests\Feature\Api;

use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LearningGroupService;

/**
 * Wer verlängert: access.renewal/group_name in der Mobile-API und die
 * Hinweise zu abgelaufenen Gruppenzugängen (Dashboard, Kursübersicht) --
 * Hinweis auf die Bootsschule statt Verlängerungscode bzw. Kauf.
 */
class GroupAccessRenewalFlagTest extends MobileApiTestCase
{
    private const SHOP = 'https://shop.example.test/verlaengerung';

    private function school(): Tenant
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(function () use ($tenant) {
            $tenant->branding()->update(['renewal_webshop_link' => self::SHOP]);
            CourseWebshopLink::create(['tenant_id' => $tenant->id, 'course_id' => $this->course()->id, 'url' => 'https://shop.example.test/src']);
        });

        return $tenant;
    }

    private function course(string $code = 'SRC'): CourseDefinition
    {
        return $this->existingCourse($code);
    }

    /** Lerngruppe mit der Person als Mitglied und ihrem Gruppenzugang ($days < 0: abgelaufen). */
    private function groupAccess(Tenant $tenant, User $user, string $name, int $days): Entitlement
    {
        return $this->onAdmin(function () use ($tenant, $user, $name, $days) {
            $owner = $this->createTenantUser($tenant, 'learner');
            $group = LearningGroup::create([
                'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => $name,
                'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
                'valid_from' => now()->subMonths(2), 'valid_until' => now()->addDays($days),
                'join_code' => LearningGroupService::newJoinCode(),
            ]);
            $member = LearningGroupMember::create([
                'tenant_id' => $tenant->id, 'group_id' => $group->id, 'user_id' => $user->id, 'email' => $user->email,
                'status' => 'active', 'joined_at' => now()->subMonths(2),
            ]);

            return Entitlement::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->course()->id,
                'status' => 'active', 'valid_from' => now()->subMonths(2), 'valid_until' => now()->addDays($days),
                'source_type' => 'group', 'source_reference' => 'group:'.$group->id.':'.$member->id,
            ]);
        });
    }

    /** Aus WordPress übernommener Gruppenplatz (ohne Lerngruppe hier). */
    private function importedGroupAccess(Tenant $tenant, User $user, int $days): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->course()->id,
            'status' => 'active', 'valid_from' => now()->subMonths(2), 'valid_until' => now()->addDays($days),
            'source_type' => 'wordpress', 'source_reference' => 'wp-group-'.$user->id,
            'import_meta' => ['source' => 'group', 'group_id' => 4711, 'order_id' => null, 'status' => 'active'],
        ]));
    }

    private function ownAccess(Tenant $tenant, User $user, ?int $days, string $code = 'SRC'): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->course($code)->id,
            'status' => 'active', 'valid_from' => now()->subMonths(2), 'valid_until' => $days === null ? null : now()->addDays($days),
            'source_type' => 'coupon',
        ]));
    }

    private function date(Entitlement $entitlement): string
    {
        return $entitlement->valid_until->timezone('Europe/Berlin')->format('d.m.Y');
    }

    /** access-Block des Kurses aus GET /courses und GET /courses/{id}. */
    private function apiAccess(Tenant $tenant, User $user): array
    {
        $list = collect($this->api('GET', '/courses', $user, $tenant)->assertOk()->json('data'))->firstWhere('id', $this->course()->id)['access'];
        $detail = $this->api('GET', '/courses/'.$this->course()->id, $user, $tenant)->assertOk()->json('data.access');
        $this->assertSame($list, $detail, 'Liste und Detail liefern denselben Zugangsstand');

        return $list;
    }

    public function test_api_reports_code_renewal_for_own_time_limited_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->ownAccess($tenant, $user, 30);

        $access = $this->apiAccess($tenant, $user);
        $this->assertSame('code', $access['renewal']);
        $this->assertNull($access['group_name']);
    }

    public function test_api_reports_school_renewal_and_group_name_for_pure_group_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->groupAccess($tenant, $user, 'Jugend (Nord)', 30);

        $access = $this->apiAccess($tenant, $user);
        $this->assertSame('school', $access['renewal']);
        $this->assertSame('Jugend (Nord)', $access['group_name']);

        // Ein eigener, länger laufender Zugang verlängert per Code.
        $this->ownAccess($tenant, $user, 60);
        $access = $this->apiAccess($tenant, $user);
        $this->assertSame('code', $access['renewal']);
        $this->assertNull($access['group_name']);
    }

    public function test_api_reports_school_renewal_without_name_for_imported_wordpress_group_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->importedGroupAccess($tenant, $user, 30);

        $access = $this->apiAccess($tenant, $user);
        $this->assertSame('school', $access['renewal']);
        $this->assertNull($access['group_name']);
    }

    public function test_api_reports_no_renewal_for_unlimited_or_missing_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');

        $access = $this->apiAccess($tenant, $user);
        $this->assertSame('not_granted', $access['status']);
        $this->assertNull($access['renewal'], 'kein Zugang');
        $this->assertNull($access['group_name']);

        $this->groupAccess($tenant, $user, 'Jugend', 30);
        $this->ownAccess($tenant, $user, null);
        $access = $this->apiAccess($tenant, $user);
        $this->assertNull($access['renewal'], 'unbefristet');
        $this->assertNull($access['group_name']);
    }

    public function test_api_reports_school_renewal_for_expired_group_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->ownAccess($tenant, $user, -20);
        $this->groupAccess($tenant, $user, 'Jugend', -5);

        $entry = collect($this->api('GET', '/courses', $user, $tenant)->assertOk()->json('data'))->firstWhere('id', $this->course()->id);
        $this->assertSame('expired', $entry['access']['status']);
        $this->assertSame('school', $entry['access']['renewal']);
        $this->assertSame('Jugend', $entry['access']['group_name']);
    }

    public function test_dashboard_and_course_list_point_expired_group_access_to_the_school(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $grant = $this->groupAccess($tenant, $user, 'Jugend (Nord)', -3);
        $name = $this->course()->name;

        // Leere Kursliste auf dem Dashboard: nur der Gruppenhinweis, kein Code.
        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee("Dein Zugang über die Lerngruppe „Jugend (Nord)“ ist am {$this->date($grant)} abgelaufen.", false)
            ->assertSee('Für eine Verlängerung wende dich an deine Bootsschule.')
            ->assertDontSee('Mit einem Verlängerungscode')
            ->assertDontSee('Code einlösen');

        // Kursübersicht: Hinweis statt Verlängerungs-Code.
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee("Dein Zugang über die Lerngruppe „Jugend (Nord)“ ist am {$this->date($grant)} abgelaufen.", false)
            ->assertDontSee('Verlängerungs-Code kaufen')
            ->assertDontSee('Verlängerungscode bei deiner Bootsschule anfragen')
            ->assertDontSee(self::SHOP);

        // Mit einem anderen laufenden Kurs: Banner „Abgelaufen“ ohne Code.
        $this->ownAccess($tenant, $user, 90, 'SBF-SEE');
        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee("Dein Zugang zu „{$name}“ über die Lerngruppe „Jugend (Nord)“ ist am {$this->date($grant)} abgelaufen.", false)
            ->assertDontSee('Abgelaufen: '.$name, false)
            ->assertDontSee('Code einlösen');
    }

    public function test_expired_imported_wordpress_group_access_names_no_group(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $grant = $this->importedGroupAccess($tenant, $user, -3);

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee("Dein Zugang über deine Lerngruppe ist am {$this->date($grant)} abgelaufen.", false)
            ->assertDontSee('Code einlösen');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('über deine Lerngruppe ist am', false)
            ->assertDontSee('Verlängerungs-Code kaufen');
    }

    public function test_expired_own_access_keeps_the_code_notice(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->ownAccess($tenant, $user, -3);
        $name = $this->course()->name;

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('Dein Kurszugang ist abgelaufen.')
            ->assertSee('Mit einem Verlängerungscode deiner Bootsschule')
            ->assertSee('Code einlösen')
            ->assertDontSee('Lerngruppe');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('Verlängerungs-Code kaufen')->assertSee(self::SHOP)
            ->assertDontSee('Lerngruppe');

        $this->ownAccess($tenant, $user, 90, 'SBF-SEE');
        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('Abgelaufen: '.$name, false)
            ->assertSee('Code einlösen')
            ->assertDontSee('Lerngruppe');
    }

    public function test_own_access_that_expired_later_than_the_group_keeps_the_code_notice(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->groupAccess($tenant, $user, 'Jugend', -10);
        $this->ownAccess($tenant, $user, -2);

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('Mit einem Verlängerungscode deiner Bootsschule')
            ->assertSee('Code einlösen')
            ->assertDontSee('Lerngruppe');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('Verlängerungs-Code kaufen')->assertDontSee('Lerngruppe');
        $this->assertSame('code', collect($this->api('GET', '/courses', $user, $tenant)->json('data'))->firstWhere('id', $this->course()->id)['access']['renewal']);
    }
}
