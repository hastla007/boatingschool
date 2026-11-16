<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\EntitlementExpiringSoon;
use App\Notifications\LearningGroupInvitation;
use App\Services\AccountErasureService;
use App\Services\InvitationAcceptance;
use App\Services\LearningGroupService;
use App\Services\PersonalDataExportService;
use App\Services\TenantOffboardingService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lerngruppen (Gruppenlizenzen): je Schule mit RLS, Verwaltung nur durch
 * den Verwalter, Plätze ohne Überzug, Beitritt per Code/Einladung über
 * /coupons/redeem, Ende des Gruppenzugangs beim Entfernen, Datenschutz (Löschung, Export,
 * Fristen) und die Betreiber-Befehle.
 */
class LearningGroupApiTest extends MobileApiTestCase
{
    /** @var list<string> */
    private array $couponIds = [];

    /** @var list<string> */
    private array $productIds = [];

    protected function tearDown(): void
    {
        // Eingelöste Codes überleben das Löschen der Schule (tenant_id NULL);
        // vor den Produkten löschen (coupon_exactly_one_target_check).
        $this->onAdmin(function () {
            Coupon::whereIn('id', $this->couponIds)->delete();
            Product::whereIn('id', $this->productIds)->delete();
        });
        parent::tearDown();
    }

    /**
     * Gruppe anlegen; die Schule bekommt dazu je Kurs so viele freie
     * Kurscodes wie Plätze ('codes' => n überschreibt das, 0 = keine).
     */
    private function group(Tenant $tenant, User $owner, array $attributes = []): LearningGroup
    {
        $codes = $attributes['codes'] ?? null;
        unset($attributes['codes']);
        $group = $this->onAdmin(fn () => LearningGroup::create($attributes + [
            'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => 'Segelverein',
            'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addYear(),
            'join_code' => LearningGroupService::newJoinCode(),
        ]));
        foreach (collect($group->scopes)->pluck('course_code')->unique() as $code) {
            $this->courseCodes($tenant, $this->existingCourse($code), $codes ?? (int) $group->seats);
        }

        return $group;
    }

    /** Freie Kurscodes im Bestand der Schule (wie aus der Shop-Lieferung). */
    private function courseCodes(Tenant $tenant, CourseDefinition $course, int $count, array $attributes = []): Collection
    {
        return $this->onAdmin(function () use ($tenant, $course, $count, $attributes) {
            $coupons = collect(range(1, $count))->filter(fn () => $count > 0)->map(fn () => Coupon::create($attributes + [
                'code' => Coupon::generateUniqueCode(), 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'batch_label' => 'Test',
            ]));
            array_push($this->couponIds, ...$coupons->pluck('id')->all());

            return $coupons->values();
        });
    }

    /** Vom Beitritt verbrauchte Codes der Person. */
    private function consumedCodes(User $user): Collection
    {
        return $this->onAdmin(fn () => Coupon::where('redeemed_by_user_id', $user->id)->get());
    }

    private function inviteRow(LearningGroup $group, string $email, array $extra = []): LearningGroupMember
    {
        return $this->onAdmin(fn () => LearningGroupMember::create($extra + [
            'tenant_id' => $group->tenant_id, 'group_id' => $group->id, 'email' => $email, 'status' => 'invited',
            'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => now(),
        ]));
    }

    private function courseEntry(Tenant $tenant, string $token, CourseDefinition $course): array
    {
        $list = $this->api('GET', '/courses?per_page=100', $token, $tenant)->assertOk();

        return collect($list->json('data'))->firstWhere('id', $course->id);
    }

    private function groupEntitlements(User $user): Collection
    {
        return $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->where('user_id', $user->id)->where('source_type', 'group')->get());
    }

    public function test_owner_sees_and_manages_only_own_groups_of_the_current_school(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $other->id, 'user_id' => $owner->id, 'role' => 'learner', 'status' => 'active']));
        $stranger = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner);
        $foreign = $this->group($other, $owner, ['name' => 'Fremd']);
        $token = $this->apiLogin($owner);

        $list = $this->api('GET', '/me/groups', $token, $tenant)->assertOk();
        $this->assertSame([$group->id], array_column($list->json('data'), 'id'));
        $this->assertSame(['groups' => 1, 'seats_total' => 3, 'seats_used' => 0], $list->json('meta'));
        $this->assertSame($group->join_code, $list->json('data.0.join_code'));
        $this->assertNull($list->json('data.0.scopes.0.capabilities'));

        // Fremde Gruppe (andere Schule) und fremder Verwalter: 404.
        $this->api('GET', "/me/groups/{$foreign->id}", $token, $tenant)->assertNotFound();
        $this->api('GET', "/me/groups/{$foreign->id}", $token, $other)->assertOk()->assertJsonPath('data.name', 'Fremd');
        $this->api('GET', "/me/groups/{$group->id}", $stranger, $tenant)->assertNotFound();
        $this->api('POST', "/me/groups/{$group->id}/invites", $stranger, $tenant, ['email' => 'x@example.test'])->assertNotFound();
        $this->api('POST', "/me/groups/{$group->id}/join-code", $stranger, $tenant)->assertNotFound();
        // Ohne Schulkontext gibt es keine Gruppen.
        $this->api('GET', '/me/groups', $token)->assertStatus(422);

        $old = $group->join_code;
        $renewed = $this->api('POST', "/me/groups/{$group->id}/join-code", $token, $tenant)->assertOk();
        $this->assertNotSame($old, $renewed->json('data.join_code'));
        $this->assertMatchesRegularExpression('/^g[0-9a-f]{32}$/', $renewed->json('data.join_code'));
    }

    public function test_invitations_are_sent_without_revealing_whether_an_account_exists_and_respect_seats(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $existing = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner, ['seats' => 2]);
        $token = $this->apiLogin($owner);
        $unknown = 'neu-'.Str::lower(Str::random(6)).'@example.test';

        $response = $this->api('POST', "/me/groups/{$group->id}/invites", $token, $tenant, [
            'emails' => [strtoupper($existing->email), $unknown, 'keine-adresse', $existing->email, 'dritte@example.test'],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.invited'));
        // Ungültig und kein Platz mehr -- keine Unterscheidung nach Konto.
        $this->assertSame(['keine-adresse', 'dritte@example.test'], $response->json('data.skipped'));
        $this->assertSame(['total' => 2, 'used' => 2, 'free' => 0], $response->json('data.group.seats'));
        $members = collect($response->json('data.group.members'));
        $this->assertSame([mb_strtolower($existing->email), $unknown], $members->pluck('email')->all());
        $this->assertSame([null, null], $members->pluck('name')->all());
        $this->assertSame([null, null], $members->pluck('user_id')->all());
        $this->assertArrayNotHasKey('invite_token', $members->first());

        foreach ([mb_strtolower($existing->email), $unknown] as $email) {
            Notification::assertSentOnDemand(LearningGroupInvitation::class,
                fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email);
        }
        Notification::assertSentOnDemandTimes(LearningGroupInvitation::class, 2);

        // Volle Gruppe: weitere Einladung wird übersprungen.
        $this->api('POST', "/me/groups/{$group->id}/invites", $token, $tenant, ['email' => 'vierte@example.test'])
            ->assertOk()->assertJsonPath('data.invited', 0)->assertJsonPath('data.skipped', ['vierte@example.test']);
        $this->api('POST', "/me/groups/{$group->id}/invites", $token, $tenant, ['emails' => []])->assertStatus(422);
    }

    public function test_joining_with_the_group_code_grants_the_course_and_fills_seats_without_overbooking(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $first = $this->createTenantUser($tenant, 'learner');
        $second = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 1]);
        $token = $this->apiLogin($first);

        $this->assertSame('not_granted', $this->courseEntry($tenant, $token, $course)['access']['status']);
        $preview = $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk();
        $this->assertTrue($preview->json('data.joinable'));
        $this->assertSame(1, $preview->json('data.free_seats'));
        $this->assertFalse($preview->json('data.personal'));

        $joined = $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => 'https://app.example.test/gruppe/'.strtoupper($group->join_code).'/'])->assertOk();
        $joined->assertJsonPath('data.type', 'group_license')->assertJsonPath('data.kind', 'group_license')
            ->assertJsonPath('data.already_redeemed', false)->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.entitlement.status', 'active');
        $this->assertSame('active', $this->courseEntry($tenant, $token, $course)['access']['status']);
        $entitlement = $this->groupEntitlements($first)->sole();
        $this->assertNull($entitlement->capabilities);
        $this->assertSame([$course->id], $this->consumedCodes($first)->pluck('course_id')->all());
        $this->assertSame($group->valid_until->toIso8601String(), $entitlement->valid_until->toIso8601String());

        // Nochmal einlösen: kein zweiter Platz, kein zweites Entitlement.
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk()->assertJsonPath('data.already_redeemed', true);
        $this->assertCount(1, $this->groupEntitlements($first));
        $this->assertCount(1, $this->consumedCodes($first));

        // Kein freier Platz mehr: einheitlich COUPON_INVALID.
        $this->api('POST', '/coupons/redeem', $second, $tenant, ['code' => $group->join_code])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->assertCount(0, $this->groupEntitlements($second));
        $this->api('GET', "/groups/join/{$group->join_code}", $second, $tenant)->assertOk()->assertJsonPath('data.joinable', false);

        // Die Datenbank verhindert zusätzlich doppelte aktive Plätze derselben Person.
        $this->expectException(QueryException::class);
        $this->onAdmin(fn () => LearningGroupMember::create([
            'tenant_id' => $tenant->id, 'group_id' => $group->id, 'user_id' => $first->id, 'status' => 'active', 'joined_at' => now(),
        ]));
    }

    public function test_seat_changes_wait_for_the_group_row_lock(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner, ['seats' => 1]);
        $service = app(LearningGroupService::class);

        // Eine andere Sitzung hält die Gruppenzeile (z. B. eine parallele Einladung):
        // Einladen und Beitreten warten darauf, statt den Platz doppelt zu vergeben.
        $other = DB::connection('pgsql_admin');
        $other->beginTransaction();
        try {
            $other->select('SELECT id FROM learning_group WHERE id = ? FOR UPDATE', [$group->id]);
            $this->asTenant($tenant, function () use ($service, $group, $owner, $user, $tenant) {
                DB::statement("SET lock_timeout = '200ms'");
                try {
                    foreach ([
                        fn () => $service->invite(LearningGroup::find($group->id), User::find($owner->id), ['a@example.test']),
                        fn () => $service->join($group->join_code, $tenant, User::find($user->id)),
                    ] as $attempt) {
                        try {
                            $attempt();
                            $this->fail('Platzänderung ohne Sperre der Gruppe.');
                        } catch (QueryException $e) {
                            $this->assertStringContainsString('lock timeout', $e->getMessage());
                        }
                    }
                } finally {
                    DB::statement('RESET lock_timeout');
                }
            });
        } finally {
            $other->rollBack();
        }
        $this->assertSame(0, $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->count()));
    }

    public function test_capability_scopes_only_open_the_named_features(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['scopes' => [['course_code' => 'SRC', 'feature' => 'exam_simulation']]]);
        $token = $this->apiLogin($user);

        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk();
        $this->assertSame(['exam'], $this->groupEntitlements($user)->sole()->capabilities);
        $entry = $this->courseEntry($tenant, $token, $course);
        $this->assertTrue($entry['features']['exam']['available']);
        $this->assertFalse($entry['features']['smarttrainer']['available']);
        $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertStatus(403);
    }

    public function test_personal_invitations_only_work_for_the_invited_address_and_the_same_school(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $invitee = $this->createTenantUser($tenant, 'learner');
        $outsider = $this->createTenantUser($other, 'learner');
        $sameSchool = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner);
        $invite = $this->inviteRow($group, strtoupper($invitee->email));
        $outsiderInvite = $this->inviteRow($group, $outsider->email, ['invited_at' => now()->subDay()]);

        // Andere Adresse: sieht aus wie ein unbekannter Code.
        $this->api('GET', "/groups/join/{$invite->invite_token}", $sameSchool, $tenant)->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->api('POST', '/coupons/redeem', $sameSchool, $tenant, ['code' => $invite->invite_token])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');

        // Teilnehmer einer anderen Schule: die Gruppe ist dort unsichtbar (RLS).
        $this->api('POST', '/coupons/redeem', $outsider, $other, ['code' => $outsiderInvite->invite_token])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->api('POST', '/coupons/redeem', $outsider, $other, ['code' => $group->join_code])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->api('POST', '/coupons/redeem', $outsider, $tenant, ['code' => $group->join_code])->assertStatus(403);
        $this->assertCount(0, $this->groupEntitlements($outsider));

        $token = $this->apiLogin($invitee);
        $this->api('GET', "/groups/join/{$invite->invite_token}", $token, $tenant)->assertOk()->assertJsonPath('data.personal', true);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $invite->invite_token])->assertOk()->assertJsonPath('data.already_redeemed', false);
        $member = $this->onAdmin(fn () => LearningGroupMember::find($invite->id));
        $this->assertSame('active', $member->status);
        $this->assertSame($invitee->id, $member->user_id);
        $this->assertNull($member->invite_token);
        $this->assertCount(1, $this->groupEntitlements($invitee));
        // Code ist verbraucht.
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $invite->invite_token])->assertStatus(422);

        // Abgelaufene Einladung gilt nicht mehr.
        $stale = $this->inviteRow($group, $sameSchool->email, ['invited_at' => now()->subDays(LearningGroupService::INVITE_DAYS + 1)]);
        $this->api('POST', '/coupons/redeem', $sameSchool, $tenant, ['code' => $stale->invite_token])->assertStatus(422);
    }

    public function test_removing_a_member_ends_only_the_group_access_and_frees_the_seat(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $fkn = $this->existingCourse('FKN');
        $group = $this->group($tenant, $owner, ['seats' => 1, 'scopes' => [['course_code' => 'SRC'], ['course_code' => 'FKN']]]);
        // Eigener, gekaufter Zugang zu FKN bleibt.
        $own = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $member->id, 'course_id' => $fkn->id, 'status' => 'active',
            'source_type' => 'manual', 'valid_from' => now()->subDay(),
        ]));
        $memberToken = $this->apiLogin($member);
        $this->api('POST', '/coupons/redeem', $memberToken, $tenant, ['code' => $group->join_code])->assertOk()->assertJsonPath('data.course_id', null);
        $this->assertCount(2, $this->groupEntitlements($member));

        $ownerToken = $this->apiLogin($owner);
        $show = $this->api('GET', "/me/groups/{$group->id}", $ownerToken, $tenant)->assertOk();
        $row = collect($show->json('data.members'))->firstWhere('user_id', $member->id);
        $this->assertSame($member->name, $row['name']);
        $this->assertSame(['total' => 1, 'used' => 1, 'free' => 0], $show->json('data.seats'));

        $after = $this->api('DELETE', "/me/groups/{$group->id}/members/{$row['id']}", $ownerToken, $tenant)->assertOk();
        $this->assertSame([], $after->json('data.members'));
        $this->assertSame(1, $after->json('data.seats.free'));
        // QA-L-2: Ende der Mitgliedschaft = abgelaufen, keine Sperre (revoked).
        $this->assertSame(['expired', 'expired'], $this->groupEntitlements($member)->pluck('status')->all());
        // Der beim Beitritt verbrauchte Kurscode bleibt verbraucht; für FKN
        // (eigener Vollzugang) wurde keiner verbraucht.
        $consumed = $this->consumedCodes($member);
        $this->assertSame([$src->id], $consumed->pluck('course_id')->all());
        $this->assertTrue($consumed->every(fn (Coupon $coupon) => $coupon->isRedeemed()));
        $this->assertSame([$fkn->id], $this->onAdmin(fn () => Coupon::freeCourseCodesOf($tenant->id)->pluck('coupon.course_id')->all()));
        // Enddatum jetzt (höchstens 1 s nach dem gerade erfolgten Beitritt, siehe valid_range_check).
        $this->assertTrue($this->groupEntitlements($member)->every(fn (Entitlement $e) => $e->valid_until !== null && $e->valid_until->lt(now()->addSeconds(5))));
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->find($own->id))->status);
        $this->assertNotSame('active', $this->courseEntry($tenant, $memberToken, $src)['access']['status']);
        $this->api('GET', "/courses/{$src->id}/overview", $memberToken, $tenant)->assertStatus(403);
        $this->assertSame('active', $this->courseEntry($tenant, $memberToken, $fkn)['access']['status']);
        $this->api('DELETE', "/me/groups/{$group->id}/members/{$row['id']}", $ownerToken, $tenant)->assertNotFound();

        // Entfernte Person kommt über den Gruppencode nicht wieder hinein (nur mit neuer Einladung).
        $this->api('POST', '/coupons/redeem', $memberToken, $tenant, ['code' => $group->join_code])->assertStatus(422);
        $this->api('GET', "/groups/join/{$group->join_code}", $memberToken, $tenant)->assertOk()->assertJsonPath('data.joinable', false);

        // QA-L-2: ein eigener Kurscode bringt den Kurs danach zurück.
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => 'QA-L2-'.strtoupper(Str::random(8)), 'course_id' => $src->id, 'tenant_id' => $tenant->id]));
        try {
            $this->api('POST', '/coupons/redeem', $memberToken, $tenant, ['code' => $coupon->code])->assertOk();
            $this->assertSame('active', $this->courseEntry($tenant, $memberToken, $src)['access']['status']);
            $this->api('GET', "/courses/{$src->id}/overview", $memberToken, $tenant)->assertOk();
        } finally {
            $this->onAdmin(fn () => Coupon::whereKey($coupon->id)->delete());
        }
    }

    public function test_inactive_groups_cannot_be_joined_or_extended(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner, ['status' => 'suspended']);
        $token = $this->apiLogin($owner);

        $this->api('POST', "/me/groups/{$group->id}/invites", $token, $tenant, ['email' => 'a@example.test'])->assertStatus(409)->assertJsonPath('code', 'GROUP_NOT_ACTIVE');
        $this->api('POST', "/me/groups/{$group->id}/join-code", $token, $tenant)->assertStatus(409);
        $this->api('GET', "/me/groups/{$group->id}", $token, $tenant)->assertOk()->assertJsonPath('data.join_code', null);
        $this->api('POST', '/coupons/redeem', $user, $tenant, ['code' => $group->join_code])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->api('GET', "/groups/join/{$group->join_code}", $user, $tenant)->assertStatus(422);
    }

    public function test_tables_are_isolated_per_school_and_members_must_belong_to_the_school(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $outsider = $this->createTenantUser($other, 'learner');
        $group = $this->group($tenant, $owner);
        $this->inviteRow($group, 'a@example.test');

        $this->asTenant($other, function () use ($group) {
            $this->assertSame(0, DB::table('learning_group')->count());
            $this->assertSame(0, DB::table('learning_group_member')->count());
            $this->assertSame(0, DB::table('learning_group')->where('id', $group->id)->update(['seats' => 99]));
        });
        $this->asTenant($tenant, function () use ($group) {
            $this->assertSame(1, DB::table('learning_group')->where('id', $group->id)->count());
            $this->assertSame(1, DB::table('learning_group_member')->count());
        });

        // Ohne Kontext (leere Einstellung) sieht die App-Rolle nichts.
        DB::statement("SET app.current_tenant_id = ''");
        $this->assertSame(0, DB::table('learning_group')->count());
        app(TenantContext::class)->clear();

        // Schreiben in eine fremde Schule scheitert an der Policy.
        $this->asTenant($other, function () use ($group, $tenant) {
            try {
                DB::table('learning_group_member')->insert(['tenant_id' => $tenant->id, 'group_id' => $group->id, 'email' => 'b@example.test', 'status' => 'invited', 'invite_token' => 'i'.bin2hex(random_bytes(16))]);
                $this->fail('Insert in fremde Schule darf nicht gelingen.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('row-level security', $e->getMessage());
            }
        });

        // Mitglied aus einer anderen Schule oder Gruppe/Schule vertauscht: Fremdschlüssel.
        foreach ([
            ['tenant_id' => $tenant->id, 'user_id' => $outsider->id],
            ['tenant_id' => $other->id, 'user_id' => $outsider->id],
        ] as $row) {
            try {
                $this->onAdmin(fn () => DB::table('learning_group_member')->insert($row + ['group_id' => $group->id, 'status' => 'active']));
                $this->fail('Mitglied außerhalb der Schule darf nicht gespeichert werden.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', $e->getMessage());
            }
        }
    }

    public function test_leaving_the_school_removes_memberships_and_invitations_but_keeps_managed_groups(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $member = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $other->id, 'user_id' => $member->id, 'role' => 'learner', 'status' => 'active']));
        $otherOwner = $this->createTenantUser($other, 'learner');
        $group = $this->group($tenant, $owner, ['seats' => 5]);
        $managed = $this->group($tenant, $member, ['name' => 'Eigene Gruppe']);
        $otherGroup = $this->group($other, $otherOwner);
        $this->api('POST', '/coupons/redeem', $member, $tenant, ['code' => $group->join_code])->assertOk();
        $this->inviteRow($group, strtoupper($member->email), ['invited_at' => now()->subDay()]);
        $otherInvite = $this->inviteRow($otherGroup, $member->email);
        $stranger = $this->inviteRow($managed, 'jemand@example.test');

        $export = $this->onAdmin(fn () => app(PersonalDataExportService::class)->export(User::findOrFail($member->id), $tenant));
        $this->assertSame(['active', 'invited'], collect($export['learning_group_memberships'])->pluck('status')->sort()->values()->all());
        $this->assertSame([['name' => 'Eigene Gruppe', 'open_invitations' => 1]], collect($export['learning_groups_managed'])->map(fn ($g) => ['name' => $g['name'], 'open_invitations' => $g['open_invitations']])->all());
        $this->assertStringNotContainsString('jemand@example.test', json_encode($export));

        $schoolExport = '';
        $this->onAdmin(function () use ($tenant, &$schoolExport) {
            app(TenantOffboardingService::class)->writeExport(Tenant::find($tenant->id), function ($chunk) use (&$schoolExport) {
                $schoolExport .= $chunk;
            });
        });
        $this->assertSame(['Segelverein', 'Eigene Gruppe'], collect(json_decode($schoolExport, true, 512, JSON_THROW_ON_ERROR)['learning_groups'])->pluck('name')->all());

        $this->asTenant($tenant, fn () => app(AccountErasureService::class)->leaveTenant(User::findOrFail($member->id), $tenant));

        $this->onAdmin(function () use ($member, $group, $managed, $otherInvite, $stranger) {
            $this->assertSame(0, LearningGroupMember::where('group_id', $group->id)->count());
            $this->assertSame(0, Entitlement::where('user_id', $member->id)->where('source_type', 'group')->where('tenant_id', $group->tenant_id)->count());
            // Selbst verwaltete Gruppe bleibt (ohne Verwalter), Einladungen darin bleiben.
            $this->assertNull(LearningGroup::find($managed->id)->owner_user_id);
            $this->assertTrue(LearningGroupMember::whereKey($stranger->id)->exists());
            // Die andere Schule ist eigener Verantwortlicher: ihre Einladung bleibt.
            $this->assertTrue(LearningGroupMember::whereKey($otherInvite->id)->exists());
        });

        // Vollständige Löschung: auch die Einladung der anderen Schule verschwindet.
        app(AccountErasureService::class)->deleteAccount(User::on('pgsql_admin')->findOrFail($member->id));
        $this->assertFalse($this->onAdmin(fn () => LearningGroupMember::whereKey($otherInvite->id)->exists()));
        $this->assertFalse($this->onAdmin(fn () => User::whereKey($member->id)->exists()));
    }

    public function test_prune_clears_expired_invitations(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner);
        $fresh = $this->inviteRow($group, 'frisch@example.test');
        $stale = $this->inviteRow($group, 'alt@example.test', ['invited_at' => now()->subDays(LearningGroupService::INVITE_DAYS + 1)]);

        $this->artisan('app:prune-personal-data', ['--mobile-api-only' => true])->assertSuccessful();

        $this->onAdmin(function () use ($fresh, $stale) {
            $this->assertSame('frisch@example.test', LearningGroupMember::find($fresh->id)->email);
            $pruned = LearningGroupMember::find($stale->id);
            $this->assertSame('expired', $pruned->status);
            $this->assertNull($pruned->email);
            $this->assertNull($pruned->invite_token);
        });
    }

    public function test_operator_commands_create_and_revoke_groups(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $user = $this->createTenantUser($tenant, 'learner');

        $this->artisan('app:learning-group-create', ['tenant' => $tenant->slug, 'owner' => $admin->email, 'name' => 'X', '--seats' => 2, '--course' => ['SRC']])->assertFailed();
        $this->artisan('app:learning-group-create', ['tenant' => $tenant->slug, 'owner' => $owner->email, 'name' => 'X', '--seats' => 0, '--course' => ['SRC']])->assertFailed();
        $this->artisan('app:learning-group-create', ['tenant' => $tenant->slug, 'owner' => $owner->email, 'name' => 'X', '--seats' => 2, '--course' => ['SRC:unbekannt']])->assertFailed();
        $this->artisan('app:learning-group-create', ['tenant' => $tenant->slug, 'owner' => strtoupper($owner->email), 'name' => 'Verein', '--seats' => 2,
            '--course' => ['src', 'FKN:exam_simulation'], '--valid-until' => now()->addMonth()->toDateString()])
            ->expectsOutputToContain('Freie Kurscodes SRC: 0')->assertSuccessful();

        $group = $this->onAdmin(fn () => LearningGroup::where('tenant_id', $tenant->id)->sole());
        $this->assertSame($owner->id, $group->owner_user_id);
        $this->assertEquals([['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'exam_simulation']], $group->scopes);

        // Der Befehl legt nur an; der Beitritt braucht je Kurs einen freien Kurscode.
        $this->api('POST', '/coupons/redeem', $user, $tenant, ['code' => $group->join_code])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        $this->courseCodes($tenant, $this->existingCourse('SRC'), 1);
        $this->courseCodes($tenant, $this->existingCourse('FKN'), 1);
        $this->api('POST', '/coupons/redeem', $user, $tenant, ['code' => $group->join_code])->assertOk();
        $this->assertCount(2, $this->groupEntitlements($user)->where('status', 'active'));
        $this->assertCount(2, $this->consumedCodes($user));
        $this->inviteRow($group, 'offen@example.test');

        $this->artisan('app:learning-group-revoke', ['group' => $group->id])->assertSuccessful();
        $this->assertSame(['expired', 'expired'], $this->groupEntitlements($user)->pluck('status')->all());
        $this->onAdmin(function () use ($group) {
            $this->assertSame('revoked', LearningGroup::find($group->id)->status);
            $this->assertSame(0, LearningGroupMember::where('group_id', $group->id)->where('status', 'invited')->count());
            $this->assertSame(0, LearningGroupMember::where('group_id', $group->id)->whereNotNull('email')->where('status', '!=', 'active')->count());
        });
    }

    public function test_joining_consumes_exactly_one_free_course_code_per_course_of_the_own_school(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $invitee = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $fkn = $this->existingCourse('FKN');
        // Teilzugang (exam_simulation) verbraucht trotzdem einen vollen Kurscode.
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 0,
            'scopes' => [['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'exam_simulation']]]);
        $product = $this->onAdmin(fn () => Product::create(['code' => 'LG-'.uniqid(), 'name' => 'Testprodukt', 'active' => true]));
        $this->productIds[] = $product->id;

        // Zählen nicht: andere Schule, anderer Kurs, plattformweit, Verlängerung,
        // gesperrt, abgelaufen, eingelöst, Produktcode, Kurscode mit Zusatzprodukt.
        $decoys = collect([
            $this->courseCodes($other, $src, 1), $this->courseCodes($other, $fkn, 1),
            $this->courseCodes($tenant, $this->existingCourse('SKN'), 1),
            $this->courseCodes($tenant, $src, 1, ['is_renewal' => true]),
            $this->courseCodes($tenant, $src, 1, ['voided_at' => now()->subDay()]),
            $this->courseCodes($tenant, $fkn, 1, ['expires_at' => now()->subMinute()]),
            $this->courseCodes($tenant, $fkn, 1, ['redeemed_at' => now()->subDay(), 'redeemed_tenant_id' => $tenant->id]),
        ])->flatten(1);
        $decoys = $decoys->merge($this->onAdmin(function () use ($tenant, $src, $product) {
            $global = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $src->id, 'tenant_id' => null]);
            $productCode = Coupon::create(['code' => Coupon::generateUniqueCode(), 'product_id' => $product->id, 'course_id' => null, 'tenant_id' => $tenant->id]);
            $bundle = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $src->id, 'tenant_id' => $tenant->id]);
            $bundle->optionProducts()->attach($product->id);
            array_push($this->couponIds, $global->id, $productCode->id, $bundle->id);

            return [$global, $productCode, $bundle];
        }));
        $snapshot = fn () => $this->onAdmin(fn () => Coupon::whereIn('id', $decoys->pluck('id'))->orderBy('id')
            ->get(['id', 'redeemed_at', 'redeemed_by_user_id'])->map(fn ($c) => [$c->id, $c->redeemed_at?->toIso8601String(), $c->redeemed_by_user_id])->all());
        $before = $snapshot();

        $ownerToken = $this->apiLogin($owner);
        $codes = $this->api('GET', "/me/groups/{$group->id}", $ownerToken, $tenant)->assertOk()->json('data.course_codes');
        $this->assertSame([
            ['course_id' => $src->id, 'course_code' => 'SRC', 'course_name' => $src->name, 'free' => 0, 'needed' => 2, 'sufficient' => false],
            ['course_id' => $fkn->id, 'course_code' => 'FKN', 'course_name' => $fkn->name, 'free' => 0, 'needed' => 2, 'sufficient' => false],
        ], $codes);

        // Ohne freie Codes: 409, nichts verbraucht, kein Platz, kein Zugang.
        $token = $this->apiLogin($user);
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.codes_available', false)->assertJsonPath('data.joinable', false);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        // Persönliche Einladung: bleibt offen.
        $invite = $this->inviteRow($group, $invitee->email);
        $this->api('POST', '/coupons/redeem', $invitee, $tenant, ['code' => $invite->invite_token])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        $stillInvited = $this->onAdmin(fn () => LearningGroupMember::find($invite->id));
        $this->assertSame(['invited', $invite->invite_token, null], [$stillInvited->status, $stillInvited->invite_token, $stillInvited->user_id]);

        // Code nur für einen der beiden Kurse: weiterhin nichts verbraucht.
        $srcCode = $this->courseCodes($tenant, $src, 1)->sole();
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        $this->assertNull($this->onAdmin(fn () => Coupon::find($srcCode->id)->redeemed_at));
        $this->assertSame(1, $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->count()));
        $this->assertCount(0, $this->groupEntitlements($user));
        $this->assertSame($before, $snapshot());

        // Mit Codes für beide Kurse: genau diese zwei werden verbraucht.
        $fknCode = $this->courseCodes($tenant, $fkn, 1)->sole();
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()->assertJsonPath('data.codes_available', true);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk()->assertJsonPath('data.already_redeemed', false);
        $member = $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->sole());
        $consumed = $this->consumedCodes($user);
        $this->assertEqualsCanonicalizing([$srcCode->id, $fknCode->id], $consumed->pluck('id')->all());
        foreach ($consumed as $coupon) {
            $this->assertSame($tenant->id, $coupon->redeemed_tenant_id);
            $this->assertNotNull($coupon->redeemed_at);
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame(['exam'], $this->groupEntitlements($user)->firstWhere('course_id', $fkn->id)->capabilities);
        // Verweis auf die Mitgliedschaft im Protokoll.
        $audit = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', 'coupon.redeem_group')->get());
        $this->assertEqualsCanonicalizing([$srcCode->id, $fknCode->id], $audit->pluck('entity_id')->all());
        $this->assertTrue($audit->every(fn (AuditLog $row) => $row->after_data['member_id'] === $member->id && $row->after_data['group_id'] === $group->id
            && $row->after_data['user_id'] === $user->id && $row->actor_user_id === $user->id));

        // Erneutes Einlösen als Mitglied verbraucht nichts.
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk()->assertJsonPath('data.already_redeemed', true);
        $this->assertCount(2, $this->consumedCodes($user));
        $after = $this->api('GET', "/me/groups/{$group->id}", $ownerToken, $tenant)->json('data.course_codes');
        $this->assertSame([0, 0], array_column($after, 'free'));
        $this->assertSame([1, 1], array_column($after, 'needed'));
    }

    public function test_parallel_joins_never_take_a_code_another_transaction_holds(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $first = $this->createTenantUser($tenant, 'learner');
        $second = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 3, 'codes' => 0]);
        [$held, $free] = $this->courseCodes($tenant, $src, 2)->all();
        $service = app(LearningGroupService::class);

        // Ein paralleler Beitritt (andere Verbindung) hat einen Code gesperrt:
        // der Beitritt wartet nicht und nimmt den anderen (SKIP LOCKED).
        $other = DB::connection('pgsql_admin');
        $join = fn (User $user) => $this->asTenant($tenant, function () use ($service, $group, $tenant, $user) {
            DB::statement("SET lock_timeout = '200ms'");
            try {
                return $service->join($group->join_code, $tenant, User::find($user->id));
            } finally {
                DB::statement('RESET lock_timeout');
            }
        });
        $other->beginTransaction();
        try {
            $other->select('SELECT id FROM coupon WHERE id = ? FOR UPDATE', [$held->id]);
            $join($first);
            $this->assertSame([$free->id], $this->consumedCodes($first)->pluck('id')->all());

            // Nur noch der gesperrte Code: kein Beitritt, kein Doppelverbrauch.
            try {
                $join($second);
                $this->fail('Beitritt mit einem Code, den eine andere Transaktion hält.');
            } catch (RuntimeException $e) {
                $this->assertSame('GROUP_NO_CODES', $e->getMessage());
            }
        } finally {
            $other->rollBack();
        }
        $this->assertNull($this->onAdmin(fn () => Coupon::find($held->id)->redeemed_at));
        $this->assertCount(0, $this->groupEntitlements($second));

        // Umgekehrt hält der Beitritt seinen Code bis zum Commit gesperrt.
        $this->asTenant($tenant, function () use ($service, $group, $tenant, $second, $held, $other) {
            DB::transaction(function () use ($service, $group, $tenant, $second, $held, $other) {
                $service->join($group->join_code, $tenant, User::find($second->id));
                $other->beginTransaction();
                try {
                    $this->assertSame([], $other->select('SELECT id FROM coupon WHERE id = ? FOR UPDATE SKIP LOCKED', [$held->id]));
                } finally {
                    $other->rollBack();
                }
            });
        });
        $this->assertSame([$held->id], $this->consumedCodes($second)->pluck('id')->all());
    }

    /** Eigener Kurszugang der Person (Standard: voller, unbefristeter Zugang von der Schule). */
    private function ownAccess(Tenant $tenant, User $user, CourseDefinition $course, array $attributes = []): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create($attributes + [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subMonth(),
        ]));
    }

    public function test_own_full_access_saves_the_course_code_but_still_takes_a_seat_and_grants_group_access(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 0]);
        // Eigener Vollzugang aus einem Kurscode, befristet kürzer als die Gruppe.
        $own = $this->ownAccess($tenant, $user, $src, ['source_type' => 'coupon', 'valid_until' => now()->addMonth()]);

        // Ohne einen einzigen freien Code: Vorschau und Beitritt klappen.
        $token = $this->apiLogin($user);
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.codes_available', true)->assertJsonPath('data.joinable', true);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk()
            ->assertJsonPath('data.already_redeemed', false)->assertJsonPath('data.entitlement.status', 'active');

        $this->assertCount(0, $this->consumedCodes($user));
        $member = $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->sole());
        $this->assertSame('active', $member->status);
        // Gruppenzugang daneben (trägt über das Ende des eigenen hinaus), eigener unverändert.
        $grant = $this->groupEntitlements($user)->sole();
        $this->assertSame([$src->id, null, 'active'], [$grant->course_id, $grant->capabilities, $grant->status]);
        $this->assertEquals($own->valid_until, $this->onAdmin(fn () => Entitlement::find($own->id))->valid_until);
        // Kursstatus: das spätere Ende (Gruppe) zählt.
        $this->assertSame($group->valid_until->getTimestamp(), strtotime($this->courseEntry($tenant, $token, $src)['access']['valid_until']));
        $ownerToken = $this->apiLogin($owner);
        $this->assertSame(1, $this->api('GET', "/me/groups/{$group->id}", $ownerToken, $tenant)->json('data.seats.used'));
        // Protokoll: Kurs ohne Code vermerkt, kein Code-Eintrag.
        $audit = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->whereIn('action', ['coupon.redeem_group', 'learning_group.join_own_access'])->get());
        $this->assertSame(['learning_group.join_own_access'], $audit->pluck('action')->all());
        $this->assertSame($group->id, $audit->sole()->entity_id);
        $this->assertSame(['user_id' => $user->id, 'member_id' => $member->id, 'course_ids_without_code' => [$src->id]], $audit->sole()->after_data);

        // Entfernen beendet nur den Gruppenzugang, der eigene bleibt.
        $this->api('DELETE', "/me/groups/{$group->id}/members/{$member->id}", $ownerToken, $tenant)->assertOk();
        $this->assertSame('expired', $this->groupEntitlements($user)->sole()->status);
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::find($own->id))->status);
    }

    public function test_partial_ended_or_group_access_is_no_own_full_access_and_still_needs_a_code(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 6, 'codes' => 0]);
        $otherGroup = $this->group($tenant, $owner, ['seats' => 6, 'codes' => 0, 'name' => 'Andere Gruppe']);

        $cases = [
            'Teilzugang' => ['capabilities' => ['exam']],
            'abgelaufen' => ['valid_from' => now()->subYear(), 'valid_until' => now()->subDay()],
            'gesperrt' => ['status' => 'suspended'],
            // 'widerrufen' (revoked) sperrt den Beitritt ganz, siehe
            // test_a_revoked_course_access_blocks_joining_a_group_of_the_course.
            'noch nicht gültig' => ['valid_from' => now()->addDay()],
            'andere Gruppe' => ['source_type' => 'group', 'source_reference' => 'group:'.$otherGroup->id.':'.Str::uuid(), 'valid_until' => now()->addYear()],
        ];
        foreach ($cases as $label => $attributes) {
            $user = $this->createTenantUser($tenant, 'learner');
            $this->ownAccess($tenant, $user, $src, $attributes);
            $token = $this->apiLogin($user);
            $this->assertFalse($this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()->json('data.codes_available'), $label);
            $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])
                ->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
            $this->assertFalse($this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->exists()), $label);

            // Mit freiem Code klappt es und verbraucht ihn.
            $code = $this->courseCodes($tenant, $src, 1)->sole();
            $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk();
            $this->assertSame([$code->id], $this->consumedCodes($user)->pluck('id')->all(), $label);
        }
        $this->assertSame(0, $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', 'learning_group.join_own_access')->count()));
    }

    public function test_mixed_group_uses_a_code_only_for_the_course_without_own_access(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $fkn = $this->existingCourse('FKN');
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 0,
            'scopes' => [['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'course']]]);
        $this->ownAccess($tenant, $user, $src);
        $srcCode = $this->courseCodes($tenant, $src, 1)->sole();
        $token = $this->apiLogin($user);

        // Für FKN (kein eigener Zugang) fehlt ein Code: 409, der SRC-Code bleibt frei.
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()->assertJsonPath('data.codes_available', false);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        $this->assertCount(0, $this->consumedCodes($user));

        $fknCode = $this->courseCodes($tenant, $fkn, 1)->sole();
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()->assertJsonPath('data.codes_available', true);
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk();
        $this->assertSame([$fknCode->id], $this->consumedCodes($user)->pluck('id')->all());
        $this->assertNull($this->onAdmin(fn () => Coupon::find($srcCode->id))->redeemed_at);
        $this->assertEqualsCanonicalizing([$src->id, $fkn->id], $this->groupEntitlements($user)->pluck('course_id')->all());

        $member = $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->sole());
        $audit = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->get());
        $this->assertSame([$fknCode->id], $audit->where('action', 'coupon.redeem_group')->pluck('entity_id')->values()->all());
        $ownEntry = $audit->where('action', 'learning_group.join_own_access')->sole();
        $this->assertSame([$src->id], $ownEntry->after_data['course_ids_without_code']);
        $this->assertSame($member->id, $ownEntry->after_data['member_id']);
        // Anzeige: weiter Plätze − aktive Mitglieder (Momentaufnahme).
        $codes = $this->api('GET', "/me/groups/{$group->id}", $this->apiLogin($owner), $tenant)->json('data.course_codes');
        $this->assertSame([[1, 1], [1, 0]], array_map(fn ($row) => [$row['needed'], $row['free']], $codes));
    }

    public function test_no_expiry_reminder_while_another_full_access_to_the_course_lasts_longer(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        // Gruppe endet bald, der eigene Zugang trägt länger: keine Erinnerung.
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 0, 'valid_until' => now()->addDays(5)]);
        $own = $this->ownAccess($tenant, $user, $src, ['valid_until' => now()->addMonths(6)]);
        $this->api('POST', '/coupons/redeem', $this->apiLogin($user), $tenant, ['code' => $group->join_code])->assertOk();

        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        Notification::assertNotSentTo($user, EntitlementExpiringSoon::class);

        // Endet der eigene Zugang zuerst, trägt die Gruppe noch zwei Tage
        // länger: genau eine Erinnerung, und zwar zum tatsächlichen Ende (Gruppe).
        $this->onAdmin(fn () => Entitlement::find($own->id)->update(['valid_until' => now()->addDays(3)]));
        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        $grant = $this->groupEntitlements($user)->sole();
        Notification::assertSentToTimes($user, EntitlementExpiringSoon::class, 1);
        Notification::assertSentTo($user, EntitlementExpiringSoon::class,
            fn ($notification) => str_contains($notification->toMail($user)->subject, $grant->valid_until->timezone('Europe/Berlin')->format('d.m.Y')));
        $this->assertNull($this->onAdmin(fn () => Entitlement::find($own->id))->expiry_reminded_at);
        $this->assertNotNull($grant->expiry_reminded_at);
    }

    public function test_codes_reserved_for_open_school_invitations_are_not_used_for_group_seats(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 0]);
        $reserved = $this->courseCodes($tenant, $src, 1)->sole();
        // Die Schule hat den einzigen Code beim Einladen einer Person reserviert.
        $invitation = $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id, 'email' => 'eingeladen-'.Str::lower(Str::random(6)).'@example.test',
            'role' => 'learner', 'coupon_code' => $reserved->code,
        ]));
        $token = $this->apiLogin($user);
        $ownerToken = $this->apiLogin($owner);

        // Weder Vorschau, Anzeige noch Beitritt zählen den reservierten Code als frei.
        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.codes_available', false)->assertJsonPath('data.joinable', false);
        $this->assertSame(0, $this->api('GET', "/me/groups/{$group->id}", $ownerToken, $tenant)->json('data.course_codes.0.free'));
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertStatus(409)->assertJsonPath('code', 'GROUP_NO_CODES');
        $this->assertNull($this->onAdmin(fn () => Coupon::find($reserved->id))->redeemed_at);
        $this->assertSame(0, $this->onAdmin(fn () => Coupon::freeCourseCodesOf($tenant->id)->count()));

        // Abgelaufene bzw. angenommene Einladungen reservieren nichts mehr.
        $this->onAdmin(fn () => TenantInvitation::whereKey($invitation->id)->update(['created_at' => now()->subDays(InvitationAcceptance::VALID_DAYS + 1)]));
        $this->assertSame(1, $this->onAdmin(fn () => Coupon::freeCourseCodesOf($tenant->id)->count()));
        $this->onAdmin(fn () => TenantInvitation::whereKey($invitation->id)->update(['created_at' => now(), 'accepted_at' => now()]));
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk();
        $this->assertSame([$reserved->id], $this->consumedCodes($user)->pluck('id')->all());
    }

    public function test_a_revoked_course_access_blocks_joining_a_group_of_the_course(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $group = $this->group($tenant, $owner, ['seats' => 2]);
        // Die Schule hat den Kurs bewusst entzogen (z. B. Rückbuchung).
        $this->ownAccess($tenant, $user, $src, ['status' => 'revoked']);
        $invite = $this->inviteRow($group, $user->email);
        $token = $this->apiLogin($user);

        $this->api('GET', "/groups/join/{$group->join_code}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.access_revoked', true)->assertJsonPath('data.joinable', false);
        foreach ([$group->join_code, $invite->invite_token] as $code) {
            $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $code])->assertStatus(409)
                ->assertJsonPath('code', 'GROUP_ACCESS_REVOKED')
                ->assertJsonPath('detail', 'Dein Zugang zu einem Kurs dieser Gruppe wurde von der Bootsschule gesperrt. Bitte wende dich an deine Bootsschule.');
        }
        // Nichts verbraucht, kein Zugang, die Einladung bleibt offen.
        $this->assertCount(0, $this->consumedCodes($user));
        $this->assertCount(0, $this->groupEntitlements($user));
        $this->assertSame('invited', $this->onAdmin(fn () => LearningGroupMember::find($invite->id))->status);
        $this->assertNotSame('active', $this->courseEntry($tenant, $token, $src)['access']['status']);

        // Ohne Sperre für diesen Kurs klappt der Beitritt (andere Person, gleiche Gruppe).
        $other = $this->createTenantUser($tenant, 'learner');
        $otherToken = $this->apiLogin($other);
        $this->api('GET', "/groups/join/{$group->join_code}", $otherToken, $tenant)->assertOk()
            ->assertJsonPath('data.access_revoked', false)->assertJsonPath('data.joinable', true);
        $this->api('POST', '/coupons/redeem', $otherToken, $tenant, ['code' => $group->join_code])->assertOk();
    }

    public function test_the_group_manager_only_sees_free_codes_up_to_the_need_of_the_group(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        $group = $this->group($tenant, $owner, ['seats' => 2, 'codes' => 7]);
        $token = $this->apiLogin($owner);

        // Der Verwalter (ein Teilnehmer) erfährt nicht den Codebestand der Schule.
        $expected = [['free' => 2, 'needed' => 2, 'sufficient' => true]];
        $pick = fn (array $rows) => array_map(fn ($row) => array_intersect_key($row, array_flip(['free', 'needed', 'sufficient'])), $rows);
        $this->assertSame($expected, $pick($this->api('GET', "/me/groups/{$group->id}", $token, $tenant)->json('data.course_codes')));
        $this->assertSame($expected, $pick($this->api('GET', '/me/groups', $token, $tenant)->json('data.0.course_codes')));

        // Zu wenige Codes: die (kleinere) Zahl bleibt sichtbar, sufficient false.
        $this->onAdmin(fn () => Coupon::where('tenant_id', $tenant->id)->limit(6)->get()->each->update(['voided_at' => now()]));
        $this->assertSame([['free' => 1, 'needed' => 2, 'sufficient' => false]], $pick($this->api('GET', "/me/groups/{$group->id}", $token, $tenant)->json('data.course_codes')));

        // Das Team der Schule (Admin-Bereich) sieht die genaue Zahl.
        $this->onAdmin(fn () => Coupon::where('tenant_id', $tenant->id)->update(['voided_at' => null]));
        $exact = $this->asTenant($tenant, fn () => app(LearningGroupService::class)->payload(LearningGroup::find($group->id), User::find($owner->id), exactCodeCounts: true));
        $this->assertSame([['free' => 7, 'needed' => 2, 'sufficient' => true]], $pick($exact['course_codes']));
    }

    public function test_a_manager_sends_at_most_a_fixed_number_of_invitations_per_day_across_groups(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'learner');
        // Je Gruppe läge das Limit bei 2 × 150 = 300 Einladungen am Tag.
        $first = $this->group($tenant, $owner, ['seats' => 150, 'codes' => 0]);
        $second = $this->group($tenant, $owner, ['seats' => 150, 'codes' => 0, 'name' => 'Zweite Gruppe']);
        $token = $this->apiLogin($owner);
        $emails = fn (string $prefix, int $count) => array_map(fn ($i) => "{$prefix}-{$i}@example.test", range(1, $count));

        $this->api('POST', "/me/groups/{$first->id}/invites", $token, $tenant, ['emails' => $emails('a', 150)])
            ->assertOk()->assertJsonPath('data.invited', 150);
        $response = $this->api('POST', "/me/groups/{$second->id}/invites", $token, $tenant, ['emails' => $emails('b', 100)])->assertOk();
        $this->assertSame(LearningGroupService::INVITES_PER_OWNER_PER_DAY - 150, $response->json('data.invited'));
        $this->assertCount(100 - (LearningGroupService::INVITES_PER_OWNER_PER_DAY - 150), $response->json('data.skipped'));
        $this->assertSame(0, $response->json('data.group.invites_remaining_today'));
        Notification::assertSentOnDemandTimes(LearningGroupInvitation::class, LearningGroupService::INVITES_PER_OWNER_PER_DAY);

        $blocked = $this->api('POST', "/me/groups/{$second->id}/invites", $token, $tenant, ['email' => 'c-1@example.test'])->assertStatus(429);
        $blocked->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertStringContainsString('bereits '.LearningGroupService::INVITES_PER_OWNER_PER_DAY.' Einladungen', $blocked->json('detail'));
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertSame(LearningGroupService::INVITES_PER_OWNER_PER_DAY, $this->onAdmin(fn () => LearningGroupMember::whereIn('group_id', [$first->id, $second->id])->count()));
    }
}
