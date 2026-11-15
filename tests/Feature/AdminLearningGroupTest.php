<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LearningGroupService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lerngruppen im Admin-Bereich: Inhaber/Admins sehen die Liste im Tab
 * "Lerngruppen", legen an (gleiche Regeln wie app:learning-group-create),
 * bearbeiten, beenden und entfernen Mitglieder -- protokolliert, nur in der
 * eigenen Schule (RLS), nicht für Ausbilder und Lernende.
 */
class AdminLearningGroupTest extends TestCase
{
    use InteractsWithTenants;

    private function group(Tenant $tenant, User $owner, array $attributes = []): LearningGroup
    {
        return $this->onAdmin(fn () => LearningGroup::create($attributes + [
            'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => 'Segelverein',
            'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addYear(),
            'join_code' => LearningGroupService::newJoinCode(),
        ]));
    }

    /** @var list<string> */
    private array $couponIds = [];

    protected function tearDown(): void
    {
        // Eingelöste Codes überleben das Löschen der Schule (tenant_id NULL).
        $this->onAdmin(fn () => Coupon::whereIn('id', $this->couponIds)->delete());
        parent::tearDown();
    }

    /** Freie Kurscodes im Bestand der Schule. */
    private function courseCodes(Tenant $tenant, string $courseCode, int $count): Collection
    {
        return $this->onAdmin(function () use ($tenant, $courseCode, $count) {
            $course = $this->existingCourse($courseCode);
            $coupons = collect(range(1, $count))->map(fn () => Coupon::create([
                'code' => Coupon::generateUniqueCode(), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'batch_label' => 'Test',
            ]));
            array_push($this->couponIds, ...$coupons->pluck('id')->all());

            return $coupons;
        });
    }

    /**
     * Beitritt; die Schule bekommt vorher je Kurs der Gruppe einen freien
     * Kurscode, der dabei verbraucht wird (Abrechnung der Plätze).
     */
    private function join(Tenant $tenant, LearningGroup $group, User $user): LearningGroupMember
    {
        $codes = collect($group->scopes)->pluck('course_code')->unique()
            ->flatMap(fn (string $code) => $this->courseCodes($tenant, $code, 1))->pluck('id');
        $member = $this->asTenant($tenant, fn () => app(LearningGroupService::class)->join($group->join_code, $tenant, $user)['member']);
        $this->assertEqualsCanonicalizing($codes->all(), $this->onAdmin(fn () => Coupon::whereIn('id', $codes)->where('redeemed_by_user_id', $user->id)->pluck('id')->all()));

        return $member;
    }

    private function groupEntitlements(User $user): Collection
    {
        return $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->where('user_id', $user->id)->where('source_type', 'group')->get());
    }

    private function audit(Tenant $tenant, string $action): Collection
    {
        return $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', $action)->get());
    }

    private function fresh(LearningGroup $group): LearningGroup
    {
        return $this->onAdmin(fn () => LearningGroup::withoutGlobalScopes()->findOrFail($group->id));
    }

    private function validCreate(User $owner, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Jugendgruppe', 'owner_user_id' => $owner->id, 'seats' => 5,
            'courses' => ['SRC' => 'course', 'FKN' => 'exam_simulation'],
            'valid_until' => now('Europe/Berlin')->addMonths(6)->toDateString(),
        ];
    }

    public function test_owner_and_admin_see_the_groups_tab_with_seats_courses_validity_and_status(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Greta Verwalterin']);
        $member = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager, [
            'name' => 'Segelverein Nord', 'seats' => 4,
            'scopes' => [['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'exam_simulation']],
            'valid_until' => now('Europe/Berlin')->setDate(2030, 5, 31)->endOfDay()->utc(),
        ]);
        $this->group($tenant, $manager, ['name' => 'Alte Gruppe', 'status' => 'revoked']);
        $this->join($tenant, $group, $member);

        foreach ([$owner, $admin] as $viewer) {
            $response = $this->actingAsInTenant($viewer, $tenant)->get('/admin')->assertOk();
            $response->assertSee('data-tab="learning-groups"', false);
            $response->assertSee('Segelverein Nord');
            $response->assertSee('Greta Verwalterin');
            $response->assertSee('1 / 4');
            $response->assertSee('Prüfungssimulation');
            $response->assertSee('Vollzugang');
            $response->assertSee('31.05.2030');
            $response->assertSee('Alte Gruppe');
            $response->assertSee('Beendet');
            $response->assertSee(route('admin.learning-groups.show', $group), false);
            $response->assertSee(route('admin.learning-groups.create'), false);
        }
    }

    public function test_create_page_offers_active_learners_and_only_offered_courses(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $active = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Aktive Anna']);
        $suspended = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Gesperrter Gerd']);
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $suspended->id)->update(['status' => 'suspended']));
        $instructor = $this->createTenantUser($tenant, 'instructor', ['display_name' => 'Ausbilder Ingo']);
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $this->existingCourse('SKN')->id]));

        $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.create'))->assertOk()
            ->assertSee('Aktive Anna')->assertDontSee('Gesperrter Gerd')->assertDontSee('Ausbilder Ingo')
            ->assertSee('name="courses[SRC]"', false)->assertDontSee('name="courses[SKN]"', false)
            ->assertSee('Prüfungssimulation');
    }

    public function test_admin_creates_a_group_with_the_command_rules_and_an_audit_entry(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $joiner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/learning-groups', $this->validCreate($manager, [
            'courses' => ['SRC' => 'course', 'FKN' => 'exam_simulation', 'SKN' => ''],
        ]));

        $group = $this->onAdmin(fn () => LearningGroup::where('tenant_id', $tenant->id)->sole());
        $response->assertRedirect(route('admin.learning-groups.show', $group))->assertSessionHasNoErrors();
        $this->assertSame('Jugendgruppe', $group->name);
        $this->assertSame($manager->id, $group->owner_user_id);
        $this->assertSame(5, $group->seats);
        $this->assertSame('school_admin', $group->source_type);
        $this->assertEquals([['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'exam_simulation']], $group->scopes);
        $this->assertSame(now('Europe/Berlin')->addMonths(6)->toDateString(), $group->valid_until->timezone('Europe/Berlin')->toDateString());
        $this->assertMatchesRegularExpression('/^g[0-9a-f]{32}$/', $group->join_code);

        $entry = $this->audit($tenant, 'learning_group.create')->sole();
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame($group->id, $entry->entity_id);
        $this->assertSame(5, $entry->after_data['seats']);
        $this->assertSame($manager->id, $entry->after_data['owner_user_id']);

        // Beitritt wie bei Gruppen des Betreibers: voller Kurs bzw. nur die Funktion.
        $this->join($tenant, $group, $joiner);
        $entitlements = $this->groupEntitlements($joiner)->keyBy(fn ($e) => $e->course_id);
        $this->assertNull($entitlements[$this->existingCourse('SRC')->id]->capabilities);
        $this->assertSame(['exam'], $entitlements[$this->existingCourse('FKN')->id]->capabilities);

        $this->actingAsInTenant($admin, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk()
            ->assertSee('Jugendgruppe')->assertSee($joiner->email)->assertSee('1 / 5');
    }

    public function test_create_validates_manager_courses_seats_and_date_with_german_messages(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $suspended = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $suspended->id)->update(['status' => 'suspended']));
        $outsider = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $this->existingCourse('SKN')->id]));

        $cases = [
            ['owner_user_id', ['owner_user_id' => $instructor->id], 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.'],
            ['owner_user_id', ['owner_user_id' => $suspended->id], 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.'],
            ['owner_user_id', ['owner_user_id' => $outsider->id], 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.'],
            ['seats', ['seats' => 0], 'Die Platzzahl muss zwischen 1 und 10000 liegen.'],
            ['courses', ['courses' => ['SRC' => '']], 'Bitte mindestens einen Kurs wählen.'],
            ['courses', ['courses' => ['SKN' => 'course']], 'Bitte nur Kurse wählen, die Ihre Bootsschule anbietet.'],
            ['courses', ['courses' => ['GIBTSNICHT' => 'course']], 'Bitte nur Kurse wählen, die Ihre Bootsschule anbietet.'],
            ['courses.SRC', ['courses' => ['SRC' => 'unbekannt']], 'Bitte für jeden Kurs einen gültigen Funktionsumfang wählen.'],
            ['valid_until', ['valid_until' => now('Europe/Berlin')->subDay()->toDateString()], 'Das Ende der Lerngruppe muss in der Zukunft liegen.'],
            ['name', ['name' => ''], 'Bitte einen Namen angeben.'],
        ];
        foreach ($cases as [$field, $override, $message]) {
            $this->actingAsInTenant($admin, $tenant)->from(route('admin.learning-groups.create'))
                ->post('/admin/learning-groups', $this->validCreate($manager, $override))
                ->assertRedirect(route('admin.learning-groups.create'))
                ->assertSessionHasErrors([$field => $message]);
        }

        $this->assertSame(0, $this->onAdmin(fn () => LearningGroup::where('tenant_id', $tenant->id)->count()));
        $this->assertCount(0, $this->audit($tenant, 'learning_group.create'));
    }

    public function test_admin_edits_name_seats_validity_and_manager(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $manager = $this->createTenantUser($tenant, 'learner');
        $newManager = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager, ['valid_until' => now()->addMonth()]);
        $this->join($tenant, $group, $member);
        $newEnd = now('Europe/Berlin')->addYears(2)->toDateString();

        $this->actingAsInTenant($owner, $tenant)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Segelverein Süd', 'seats' => 8, 'valid_until' => $newEnd, 'owner_user_id' => $newManager->id,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Lerngruppe gespeichert.');

        $fresh = $this->fresh($group);
        $this->assertSame('Segelverein Süd', $fresh->name);
        $this->assertSame(8, $fresh->seats);
        $this->assertSame($newManager->id, $fresh->owner_user_id);
        $this->assertSame($newEnd, $fresh->valid_until->timezone('Europe/Berlin')->toDateString());
        // Das neue Ende gilt auch für den bestehenden Gruppenzugang.
        $this->assertSame($fresh->valid_until->toIso8601String(), $this->groupEntitlements($member)->sole()->valid_until->toIso8601String());

        $entry = $this->audit($tenant, 'learning_group.update')->sole();
        $this->assertSame($owner->id, $entry->actor_user_id);
        $this->assertSame(['name' => 'Segelverein', 'seats' => 3, 'valid_until' => $group->valid_until->utc()->toIso8601String(), 'owner_user_id' => $manager->id], $entry->before_data);
        $this->assertSame('Segelverein Süd', $entry->after_data['name']);
        $this->assertSame($newManager->id, $entry->after_data['owner_user_id']);

        // Der neue Verwalter verwaltet die Gruppe in der App, der alte nicht mehr.
        $service = app(LearningGroupService::class);
        $this->asTenant($tenant, fn () => $this->assertSame($group->id, $service->owned($newManager, $group->id)->id));
    }

    public function test_extending_an_expired_group_revives_the_group_access_of_active_members(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager);
        $this->join($tenant, $group, $member);
        // Abgelaufen: Gruppe und Zugang (wie nach app:expire-entitlements).
        $this->onAdmin(function () use ($group, $member) {
            DB::table('learning_group')->where('id', $group->id)->update(['valid_from' => now()->subDays(3), 'valid_until' => now()->subDay()]);
            Entitlement::withoutGlobalScopes()->where('user_id', $member->id)->update(['status' => 'expired', 'valid_from' => now()->subDays(3), 'valid_until' => now()->subDay()]);
        });
        $this->actingAsInTenant($admin, $tenant)->get('/admin')->assertOk()->assertSee('Abgelaufen');

        // Name ändern geht auch ohne neues Ende (das alte liegt in der Vergangenheit).
        $expiredDay = $this->fresh($group)->valid_until->timezone('Europe/Berlin')->toDateString();
        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Umbenannt', 'seats' => 3, 'valid_until' => $expiredDay, 'owner_user_id' => $manager->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('expired', $this->groupEntitlements($member)->sole()->status);

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Umbenannt', 'seats' => 3, 'valid_until' => '', 'owner_user_id' => $manager->id,
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->fresh($group)->valid_until);
        $entitlement = $this->groupEntitlements($member)->sole();
        $this->assertSame('active', $entitlement->status);
        $this->assertNull($entitlement->valid_until);
    }

    public function test_seats_cannot_drop_below_occupied_seats(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager, ['seats' => 5]);
        $this->join($tenant, $group, $this->createTenantUser($tenant, 'learner'));
        $this->join($tenant, $group, $this->createTenantUser($tenant, 'learner'));
        // Offene Einladung belegt einen Platz, eine abgelaufene nicht.
        $this->onAdmin(function () use ($group) {
            foreach ([now(), now()->subDays(LearningGroupService::INVITE_DAYS + 1)] as $invitedAt) {
                LearningGroupMember::create(['tenant_id' => $group->tenant_id, 'group_id' => $group->id, 'email' => uniqid().'@example.test',
                    'status' => 'invited', 'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => $invitedAt]);
            }
        });
        $payload = fn (int $seats) => ['name' => 'Segelverein', 'seats' => $seats, 'valid_until' => $group->valid_until->timezone('Europe/Berlin')->toDateString(), 'owner_user_id' => $manager->id];

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), $payload(2))
            ->assertSessionHasErrors(['seats' => 'Die Platzzahl darf nicht unter den belegten Plätzen (3) liegen.']);
        $this->assertSame(5, $this->fresh($group)->seats);
        $this->assertCount(0, $this->audit($tenant, 'learning_group.update'));

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), $payload(3))->assertSessionHasNoErrors();
        $this->assertSame(3, $this->fresh($group)->seats);
    }

    public function test_manager_can_only_be_changed_to_an_active_learner_of_the_school(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $suspended = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $suspended->id)->update(['status' => 'suspended']));
        $outsider = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner');
        $group = $this->group($tenant, $manager);

        foreach ([$instructor->id, $suspended->id, $outsider->id, $admin->id, (string) Str::uuid()] as $candidate) {
            $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), [
                'name' => 'Segelverein', 'seats' => 3, 'valid_until' => $group->valid_until->timezone('Europe/Berlin')->toDateString(), 'owner_user_id' => $candidate,
            ])->assertSessionHasErrors(['owner_user_id' => 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.']);
        }
        $this->assertSame($manager->id, $this->fresh($group)->owner_user_id);
        $this->assertCount(0, $this->audit($tenant, 'learning_group.update'));
    }

    public function test_revoking_ends_all_group_access_and_is_logged(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $manager = $this->createTenantUser($tenant, 'learner');
        $first = $this->createTenantUser($tenant, 'learner');
        $second = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager);
        $this->join($tenant, $group, $first);
        $this->join($tenant, $group, $second);
        $own = $this->grantEntitlement($tenant, $first, $this->existingCourse('SKN'));

        $this->actingAsInTenant($owner, $tenant)->post(route('admin.learning-groups.revoke', $group))
            ->assertRedirect()->assertSessionHas('status', 'Lerngruppe beendet.');

        $this->assertSame('revoked', $this->fresh($group)->status);
        $this->assertSame(['expired'], $this->groupEntitlements($first)->pluck('status')->all());
        $this->assertSame(['expired'], $this->groupEntitlements($second)->pluck('status')->all());
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->find($own->id))->status);
        // Gruppenende gibt die verbrauchten Kurscodes nicht zurück.
        $this->assertSame(2, $this->onAdmin(fn () => Coupon::whereIn('id', $this->couponIds)->whereNotNull('redeemed_at')->count()));
        $this->assertSame(0, $this->onAdmin(fn () => Coupon::freeCourseCodesOf($tenant->id)->count()));
        $entry = $this->audit($tenant, 'learning_group.revoke')->sole();
        $this->assertSame($owner->id, $entry->actor_user_id);
        $this->assertSame(2, $entry->after_data['active_members']);

        // Beendet bleibt beendet: kein zweites Beenden, kein Bearbeiten.
        $this->actingAsInTenant($owner, $tenant)->post(route('admin.learning-groups.revoke', $group))->assertSessionHasErrors('group');
        $this->actingAsInTenant($owner, $tenant)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Neu', 'seats' => 3, 'valid_until' => '', 'owner_user_id' => $manager->id,
        ])->assertSessionHasErrors(['group' => 'Eine beendete Lerngruppe kann nicht mehr geändert werden.']);
        $this->assertSame('Segelverein', $this->fresh($group)->name);
        $this->assertCount(1, $this->audit($tenant, 'learning_group.revoke'));
        $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk()
            ->assertDontSee('Lerngruppe beenden</button>', false);
    }

    public function test_removing_a_member_ends_only_their_group_access(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $leaving = $this->createTenantUser($tenant, 'learner');
        $staying = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager);
        $member = $this->join($tenant, $group, $leaving);
        $this->join($tenant, $group, $staying);
        $own = $this->grantEntitlement($tenant, $leaving, $this->existingCourse('SKN'));

        $this->actingAsInTenant($admin, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk()
            ->assertSee($leaving->email)->assertSee($staying->email);
        $this->actingAsInTenant($admin, $tenant)->delete(route('admin.learning-groups.members.destroy', [$group, $member->id]))
            ->assertRedirect()->assertSessionHas('status', 'Mitglied entfernt.');

        $this->assertSame(['expired'], $this->groupEntitlements($leaving)->pluck('status')->all());
        $this->assertSame(['active'], $this->groupEntitlements($staying)->pluck('status')->all());
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->find($own->id))->status);
        $this->assertSame('removed', $this->onAdmin(fn () => LearningGroupMember::find($member->id))->status);
        $this->assertSame('active', $this->fresh($group)->status);
        // Der Code des entfernten Mitglieds bleibt verbraucht.
        $this->assertSame(1, $this->onAdmin(fn () => Coupon::where('redeemed_by_user_id', $leaving->id)->whereNotNull('redeemed_at')->count()));
        $this->assertSame(0, $this->onAdmin(fn () => Coupon::freeCourseCodesOf($tenant->id)->count()));

        $entry = $this->audit($tenant, 'learning_group.member_remove')->sole();
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame($leaving->id, $entry->before_data['user_id']);

        // Schon entfernt: 404, kein zweiter Eintrag.
        $this->actingAsInTenant($admin, $tenant)->delete(route('admin.learning-groups.members.destroy', [$group, $member->id]))->assertNotFound();
        $this->assertCount(1, $this->audit($tenant, 'learning_group.member_remove'));
    }

    public function test_instructors_and_learners_are_forbidden(): void
    {
        $tenant = $this->createTestTenant();
        $manager = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $group = $this->group($tenant, $manager);
        $row = $this->join($tenant, $group, $member);

        foreach ([$instructor, $manager] as $user) {
            $this->actingAsInTenant($user, $tenant)->get(route('admin.learning-groups.create'))->assertForbidden();
            $this->actingAsInTenant($user, $tenant)->post('/admin/learning-groups', $this->validCreate($manager))->assertForbidden();
            $this->actingAsInTenant($user, $tenant)->get(route('admin.learning-groups.show', $group))->assertForbidden();
            $this->actingAsInTenant($user, $tenant)->patch(route('admin.learning-groups.update', $group), ['name' => 'X', 'seats' => 9, 'owner_user_id' => $manager->id])->assertForbidden();
            $this->actingAsInTenant($user, $tenant)->post(route('admin.learning-groups.revoke', $group))->assertForbidden();
            $this->actingAsInTenant($user, $tenant)->delete(route('admin.learning-groups.members.destroy', [$group, $row->id]))->assertForbidden();
        }
        // Ausbilder sehen den Tab nicht.
        $this->actingAsInTenant($instructor, $tenant)->get('/admin')->assertOk()
            ->assertDontSee('data-tab="learning-groups"', false)->assertDontSee('Segelverein');

        $this->assertSame(1, $this->onAdmin(fn () => LearningGroup::where('tenant_id', $tenant->id)->count()));
        $fresh = $this->fresh($group);
        $this->assertSame(['Segelverein', 3, 'active'], [$fresh->name, $fresh->seats, $fresh->status]);
        $this->assertSame(['active'], $this->groupEntitlements($member)->pluck('status')->all());
    }

    public function test_another_school_neither_sees_nor_changes_the_group(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $manager = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $otherAdmin = $this->createTenantUser($other, 'owner');
        $otherLearner = $this->createTenantUser($other, 'learner');
        $group = $this->group($tenant, $manager, ['name' => 'Geheime Gruppe']);
        $row = $this->join($tenant, $group, $member);

        $this->actingAsInTenant($otherAdmin, $other)->get('/admin')->assertOk()->assertDontSee('Geheime Gruppe');
        $this->actingAsInTenant($otherAdmin, $other)->get(route('admin.learning-groups.show', $group))->assertNotFound();
        $this->actingAsInTenant($otherAdmin, $other)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Gekapert', 'seats' => 9, 'valid_until' => '', 'owner_user_id' => $otherLearner->id,
        ])->assertNotFound();
        $this->actingAsInTenant($otherAdmin, $other)->post(route('admin.learning-groups.revoke', $group))->assertNotFound();
        $this->actingAsInTenant($otherAdmin, $other)->delete(route('admin.learning-groups.members.destroy', [$group, $row->id]))->assertNotFound();

        $fresh = $this->fresh($group);
        $this->assertSame(['Geheime Gruppe', 3, 'active', $manager->id], [$fresh->name, $fresh->seats, $fresh->status, $fresh->owner_user_id]);
        $this->assertSame(['active'], $this->groupEntitlements($member)->pluck('status')->all());
        $this->assertCount(0, $this->onAdmin(fn () => AuditLog::where('action', 'like', 'learning_group.%')->whereIn('tenant_id', [$tenant->id, $other->id])->get()));

        // Auch die Datenbank lässt keine Gruppe für eine fremde Schule zu (RLS, App-Rolle).
        $this->asTenant($other, function () use ($tenant, $manager) {
            try {
                DB::table('learning_group')->insert(['tenant_id' => $tenant->id, 'owner_user_id' => $manager->id, 'name' => 'X', 'seats' => 1,
                    'scopes' => json_encode([['course_code' => 'SRC']]), 'join_code' => LearningGroupService::newJoinCode()]);
                $this->fail('Gruppe für eine fremde Schule darf nicht entstehen.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());
            }
        });
    }

    public function test_superadmin_sees_the_groups_of_a_school_read_only(): void
    {
        $tenant = $this->createTestTenant();
        $manager = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Vera Verwalterin']);
        $group = $this->group($tenant, $manager, ['name' => 'Kutterclub', 'seats' => 7]);
        $this->join($tenant, $group, $this->createTenantUser($tenant, 'learner'));

        $this->actingAs($this->createSuperAdmin())->get("/superadmin/tenants/{$tenant->id}")->assertOk()
            ->assertSee('Lerngruppen')->assertSee('Kutterclub')->assertSee('Vera Verwalterin')->assertSee('1 / 7')
            ->assertDontSee(route('admin.learning-groups.show', $group), false);
    }

    public function test_dashboard_query_count_does_not_grow_with_groups_and_members(): void
    {
        /** @return array{0:int, 1:int} alle Abfragen, Abfragen auf learning_group* */
        $count = function (int $groups): array {
            $tenant = $this->createTestTenant();
            $owner = $this->createTenantUser($tenant, 'owner');
            foreach (range(1, $groups) as $i) {
                $manager = $this->createTenantUser($tenant, 'learner');
                $group = $this->group($tenant, $manager, ['scopes' => [['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'question_bank']]]);
                $this->join($tenant, $group, $this->createTenantUser($tenant, 'learner'));
            }
            $all = 0;
            $groupQueries = 0;
            $listening = true;
            DB::listen(function ($query) use (&$all, &$groupQueries, &$listening) {
                if ($listening) {
                    $all++;
                    $groupQueries += str_contains($query->sql, 'learning_group') ? 1 : 0;
                }
            });
            $this->actingAsInTenant($owner, $tenant)->get('/admin')->assertOk()->assertSee('Segelverein');
            $listening = false;

            return [$all, $groupQueries];
        };

        [$one, $groupQueriesOne] = $count(1);
        [$four, $groupQueriesFour] = $count(4);

        // Eine Abfrage für die Gruppen (belegte Plätze als Unterabfrage),
        // unabhängig von Zahl der Gruppen und Mitglieder.
        $this->assertSame(1, $groupQueriesOne);
        $this->assertSame(1, $groupQueriesFour);
        // Übrige Abfragen: wie AdminAndTenantShowPerformanceTest (mehr Teilnehmer, höchstens 3 mehr).
        $this->assertLessThanOrEqual(3, $four - $one, "Admin-Dashboard braucht mit 4 Lerngruppen {$four} statt {$one} Abfragen.");
    }

    public function test_create_and_detail_pages_show_free_course_codes_and_warn_when_seats_exceed_them(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'owner');
        $manager = $this->createTenantUser($tenant, 'learner');
        $this->courseCodes($tenant, 'SRC', 2);
        // Codes einer anderen Schule zählen nicht.
        $this->courseCodes($other, 'SRC', 3);
        $this->courseCodes($other, 'FKN', 3);
        $codesTab = e(route('admin.dashboard', ['tab' => 'coupons']));

        $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.create'))->assertOk()
            ->assertSee('data-free-codes="SRC">Freie Kurscodes: 2', false)
            ->assertSee('data-free-codes="FKN">Freie Kurscodes: 0', false)
            ->assertSee('Mehr Plätze als freie Kurscodes')
            ->assertSee('Personen mit eigenem Kurszugang verbrauchen keinen Code.')
            ->assertSee($codesTab, false);

        // 3 Plätze, aber nur 2 SRC- und keine FKN-Codes: Warnung (Platzzahl bleibt frei wählbar).
        $group = $this->group($tenant, $manager, [
            'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'question_bank']],
        ]);
        $page = $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk();
        $page->assertSeeInOrder(['data-free-codes="SRC"', '2 frei', 'data-free-codes="FKN"', '0 frei'], false)
            ->assertSee('Achtung: Für')->assertSee('offene Plätze (3)')->assertSee($codesTab, false)
            ->assertSee('Personen mit eigenem Kurszugang verbrauchen keinen Code');

        // Genug Codes für alle offenen Plätze: keine Warnung mehr.
        $this->courseCodes($tenant, 'SRC', 1);
        $this->courseCodes($tenant, 'FKN', 3);
        $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk()
            ->assertSeeInOrder(['data-free-codes="SRC"', '3 frei', 'data-free-codes="FKN"', '3 frei'], false)
            ->assertDontSee('Achtung: Für');
    }

    public function test_members_who_joined_before_billing_are_not_charged_retroactively(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $manager = $this->createTenantUser($tenant, 'learner');
        $legacy = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $manager, ['seats' => 2]);
        // Bestand: beigetreten, bevor Plätze mit Kurscodes abgerechnet wurden.
        $member = $this->onAdmin(fn () => LearningGroupMember::create([
            'tenant_id' => $tenant->id, 'group_id' => $group->id, 'user_id' => $legacy->id, 'status' => 'active', 'joined_at' => now()->subMonth(),
        ]));
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $legacy->id, 'course_id' => $this->existingCourse('SRC')->id,
            'source_type' => 'group', 'source_reference' => 'group:'.$group->id.':'.$member->id,
            'status' => 'active', 'valid_from' => now()->subMonth(), 'valid_until' => $group->valid_until,
        ]));

        // Erneutes Einlösen ohne freie Codes: bleibt Mitglied, nichts verbraucht.
        $result = $this->asTenant($tenant, fn () => app(LearningGroupService::class)->join($group->join_code, $tenant, $legacy));
        $this->assertTrue($result['already']);
        // Ende verlängern gilt weiter für den Bestand, ohne Codes.
        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.learning-groups.update', $group), [
            'name' => 'Segelverein', 'seats' => 2, 'owner_user_id' => $manager->id,
            'valid_until' => now('Europe/Berlin')->addYears(2)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(['active'], $this->groupEntitlements($legacy)->pluck('status')->all());
        $this->assertSame(0, $this->onAdmin(fn () => Coupon::where('redeemed_by_user_id', $legacy->id)->count()));
        $this->assertSame(0, $this->audit($tenant, 'coupon.redeem_group')->count());
        // Nur der noch freie Platz braucht einen Code.
        $this->actingAsInTenant($admin, $tenant)->get(route('admin.learning-groups.show', $group))->assertOk()->assertSee('offene Plätze (1)');
    }
}
