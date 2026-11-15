<?php

namespace Tests\Feature\Api;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LearningGroupInvitation;
use App\Services\AccountErasureService;
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

/**
 * Lerngruppen (Gruppenlizenzen): je Schule mit RLS, Verwaltung nur durch
 * den Verwalter, Plätze ohne Überzug, Beitritt per Code/Einladung über
 * /coupons/redeem, Widerruf beim Entfernen, Datenschutz (Löschung, Export,
 * Fristen) und die Betreiber-Befehle.
 */
class LearningGroupApiTest extends MobileApiTestCase
{
    private function group(Tenant $tenant, User $owner, array $attributes = []): LearningGroup
    {
        return $this->onAdmin(fn () => LearningGroup::create($attributes + [
            'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => 'Segelverein',
            'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
            'valid_from' => now()->subMinute(), 'valid_until' => now()->addYear(),
            'join_code' => LearningGroupService::newJoinCode(),
        ]));
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
        $this->assertSame($group->valid_until->toIso8601String(), $entitlement->valid_until->toIso8601String());

        // Nochmal einlösen: kein zweiter Platz, kein zweites Entitlement.
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $group->join_code])->assertOk()->assertJsonPath('data.already_redeemed', true);
        $this->assertCount(1, $this->groupEntitlements($first));

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

    public function test_removing_a_member_revokes_only_the_group_access_and_frees_the_seat(): void
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
        $this->assertSame(['revoked', 'revoked'], $this->groupEntitlements($member)->pluck('status')->all());
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::withoutGlobalScopes()->find($own->id))->status);
        $this->assertNotSame('active', $this->courseEntry($tenant, $memberToken, $src)['access']['status']);
        $this->api('GET', "/courses/{$src->id}/overview", $memberToken, $tenant)->assertStatus(403);
        $this->assertSame('active', $this->courseEntry($tenant, $memberToken, $fkn)['access']['status']);
        $this->api('DELETE', "/me/groups/{$group->id}/members/{$row['id']}", $ownerToken, $tenant)->assertNotFound();

        // Entfernte Person kommt über den Gruppencode nicht wieder hinein (nur mit neuer Einladung).
        $this->api('POST', '/coupons/redeem', $memberToken, $tenant, ['code' => $group->join_code])->assertStatus(422);
        $this->api('GET', "/groups/join/{$group->join_code}", $memberToken, $tenant)->assertOk()->assertJsonPath('data.joinable', false);
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
            '--course' => ['src', 'FKN:exam_simulation'], '--valid-until' => now()->addMonth()->toDateString()])->assertSuccessful();

        $group = $this->onAdmin(fn () => LearningGroup::where('tenant_id', $tenant->id)->sole());
        $this->assertSame($owner->id, $group->owner_user_id);
        $this->assertEquals([['course_code' => 'SRC', 'feature' => 'course'], ['course_code' => 'FKN', 'feature' => 'exam_simulation']], $group->scopes);

        $this->api('POST', '/coupons/redeem', $user, $tenant, ['code' => $group->join_code])->assertOk();
        $this->assertCount(2, $this->groupEntitlements($user)->where('status', 'active'));
        $this->inviteRow($group, 'offen@example.test');

        $this->artisan('app:learning-group-revoke', ['group' => $group->id])->assertSuccessful();
        $this->assertSame(['revoked', 'revoked'], $this->groupEntitlements($user)->pluck('status')->all());
        $this->onAdmin(function () use ($group) {
            $this->assertSame('revoked', LearningGroup::find($group->id)->status);
            $this->assertSame(0, LearningGroupMember::where('group_id', $group->id)->where('status', 'invited')->count());
            $this->assertSame(0, LearningGroupMember::where('group_id', $group->id)->whereNotNull('email')->where('status', '!=', 'active')->count());
        });
    }
}
