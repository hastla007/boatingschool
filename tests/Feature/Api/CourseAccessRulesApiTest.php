<?php

namespace Tests\Feature\Api;

use App\Api\CourseAccess;
use App\Models\CourseAccessRule;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

/**
 * Zugriffsregeln der Mobile-API (WP-Parität): Funktionszugänge über
 * entitlement.capabilities, Schulregeln free / free_limited / drip aus
 * course_access_rule (mit RLS), der Schalter default_access und die
 * Import-Befehle.
 */
class CourseAccessRulesApiTest extends MobileApiTestCase
{
    private function rule(Tenant $tenant, CourseDefinition $course, string $feature, string $type, ?int $limit = null, ?string $module = null, array $extra = []): CourseAccessRule
    {
        return $this->onAdmin(fn () => CourseAccessRule::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'feature_code' => $feature,
            'access_type' => $type, 'limit_value' => $limit, 'module_code' => $module,
        ] + $extra));
    }

    private function courseEntry(Tenant $tenant, string $token, CourseDefinition $course): array
    {
        $list = $this->api('GET', '/courses?per_page=100', $token, $tenant)->assertOk();

        return collect($list->json('data'))->firstWhere('id', $course->id);
    }

    private function partialEntitlement(Tenant $tenant, User $user, CourseDefinition $course, array $capabilities, array $extra = []): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subMinute(),
            'capabilities' => $capabilities,
        ] + $extra));
    }

    public function test_free_limited_rule_gives_a_trial_with_the_first_questions_per_module(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->rule($tenant, $course, 'question_bank', 'free_limited', 2);
        $token = $this->apiLogin($user);
        $moduleCount = $this->onAdmin(fn () => $course->modules()->count());

        $entry = $this->courseEntry($tenant, $token, $course);
        $this->assertSame('limited', $entry['access']['status']);
        $this->assertTrue($entry['access']['trial']);
        $this->assertFalse($entry['access']['purchased']);
        $this->assertSame(['smarttrainer' => 2], $entry['access']['limits']);
        $this->assertSame(['available' => true, 'full' => false, 'limit' => 2], $entry['features']['smarttrainer']);
        $this->assertFalse($entry['features']['exam']['available']);
        $this->assertSame(2 * $moduleCount, $entry['progress']['total']);

        $progress = $this->api('GET', "/courses/{$course->id}/progress", $token, $tenant)->assertOk();
        $this->assertSame(2, $progress->json('data.limit'));
        foreach ($progress->json('data.modules') as $module) {
            $this->assertLessThanOrEqual(2, $module['question_count']);
            $this->assertSame('trial', $module['lock_reason']);
            $this->assertGreaterThan(2, $module['catalog_count']);
        }

        // Der Probezugang öffnet nur den Fragenkatalog, nicht die Prüfung.
        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertOk();
        $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_rules_of_another_school_expired_rules_and_disabled_courses_grant_nothing(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $fkn = $this->existingCourse('FKN');
        $ubi = $this->existingCourse('UBI');
        $this->rule($other, $src, 'question_bank', 'free');
        $this->rule($tenant, $fkn, 'question_bank', 'free', null, null, ['valid_until' => now()->subDay()]);
        $this->rule($tenant, $ubi, 'question_bank', 'free');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $ubi->id]));
        $token = $this->apiLogin($user);

        $this->assertSame('not_granted', $this->courseEntry($tenant, $token, $src)['access']['status']);
        $this->assertSame('not_granted', $this->courseEntry($tenant, $token, $fkn)['access']['status']);
        foreach ([$src, $fkn, $ubi] as $course) {
            $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertStatus(403);
        }
        $this->api('GET', "/courses/{$ubi->id}", $token, $tenant)->assertNotFound();
    }

    public function test_free_rule_opens_a_feature_without_purchase(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->rule($tenant, $course, 'question_bank', 'free');
        $token = $this->apiLogin($user);

        $entry = $this->courseEntry($tenant, $token, $course);
        $this->assertSame('active', $entry['access']['status']);
        $this->assertFalse($entry['access']['purchased']);
        $this->assertTrue($entry['features']['smarttrainer']['full']);
        $this->assertFalse($entry['features']['exam']['available']);
        $this->api('GET', "/courses/{$course->id}/modules", $token, $tenant)->assertOk();
    }

    public function test_capability_limited_entitlement_only_opens_that_feature(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->partialEntitlement($tenant, $user, $course, ['exam']);
        $this->onAdmin(fn () => CourseWebshopLink::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'url' => 'https://shop.example.test/src']));
        $token = $this->apiLogin($user);

        $entry = $this->courseEntry($tenant, $token, $course);
        $this->assertSame('active', $entry['access']['status']);
        $this->assertTrue($entry['access']['purchased']);
        $this->assertTrue($entry['features']['exam']['full']);
        $this->assertFalse($entry['features']['smarttrainer']['available']);
        $this->assertSame(0, $entry['progress']['total']);

        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('GET', "/courses/{$course->id}/favorites", $token, $tenant)->assertStatus(403);
        $this->api('GET', "/courses/{$course->id}/videos", $token, $tenant)->assertStatus(403);
        // QA-L-4: die gemeldete Funktion ist auch nutzbar (Prüfung starten, ansehen).
        $options = $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->assertOk()->json('data');
        $this->assertNotEmpty($options);
        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $options[0]['id']])->assertCreated()->json('data');
        $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->assertOk();

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertSame(count($options), $detail->json('data.exam_options_count'));
        // Kauf-Link bleibt, solange der volle Kurszugang fehlt.
        $this->assertSame('https://shop.example.test/src', $this->courseEntry($tenant, $token, $course)['webshop_url']);
    }

    public function test_drip_rule_locks_a_module_until_the_unlock_day(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $module = $this->onAdmin(fn () => $course->modules()->get()->last());
        $this->rule($tenant, $course, 'question_bank', 'drip', 7, (string) $module->code);
        $token = $this->apiLogin($user);

        $modules = collect($this->api('GET', "/courses/{$course->id}/modules", $token, $tenant)->assertOk()->json('data'));
        $locked = $modules->firstWhere('code', (string) $module->code);
        $this->assertTrue($locked['locked']);
        $this->assertSame('drip', $locked['lock_reason']);
        $this->assertNotNull($locked['unlock_at']);
        $this->assertSame(0, $locked['question_count']);
        $this->assertFalse($modules->firstWhere('code', '!=', (string) $module->code)['locked']);

        $this->travel(8)->days();
        $modules = collect($this->api('GET', "/courses/{$course->id}/modules", $this->apiLogin($user), $tenant)->assertOk()->json('data'));
        $unlocked = $modules->firstWhere('code', (string) $module->code);
        $this->assertFalse($unlocked['locked']);
        $this->assertGreaterThan(0, $unlocked['question_count']);
    }

    public function test_logged_in_default_access_is_an_explicit_switch(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $token = $this->apiLogin($user);

        $this->assertSame('entitlement', config('mobile_api.default_access'));
        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertStatus(403);

        config(['mobile_api.default_access' => 'logged_in']);
        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertOk();
        $this->assertSame('active', $this->courseEntry($tenant, $token, $course)['access']['status']);
    }

    public function test_course_detail_is_visible_for_offered_courses_with_locked_features(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $token = $this->apiLogin($user);

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertSame('not_granted', $detail->json('data.access.status'));
        $this->assertFalse($detail->json('data.features.smarttrainer.available'));
        $this->assertSame(0, $detail->json('data.exam_options_count'));
        $this->assertSame(['hidden' => false, 'recommended' => false, 'recommended_for' => []], $detail->json('data.relation'));
    }

    public function test_owned_combination_course_hides_its_parts_in_the_catalogue(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $user, $this->existingCourse('SBF-SEE-BIN'));
        $token = $this->apiLogin($user);

        $this->assertTrue($this->courseEntry($tenant, $token, $this->existingCourse('SBF-SEE'))['relation']['hidden']);
        $this->assertFalse($this->courseEntry($tenant, $token, $this->existingCourse('SBF-SEE-BIN'))['relation']['hidden']);
    }

    public function test_course_access_rule_table_is_isolated_per_school(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $course = $this->existingCourse('SRC');
        $this->rule($tenant, $course, 'question_bank', 'free');
        $this->rule($other, $course, 'question_bank', 'free');

        $visible = $this->asTenant($tenant, fn () => DB::table('course_access_rule')->whereIn('tenant_id', [$tenant->id, $other->id])->pluck('tenant_id')->all());
        $this->assertSame([$tenant->id], $visible);

        $this->expectException(QueryException::class);
        $this->asTenant($tenant, fn () => DB::table('course_access_rule')->insert([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'feature_code' => 'downloads', 'access_type' => 'free',
        ]));
    }

    public function test_access_status_survives_a_second_request_in_the_same_process(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $token = $this->apiLogin($user);

        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertStatus(403);
        $this->rule($tenant, $course, 'question_bank', 'free_limited', 1);
        $this->api('GET', "/courses/{$course->id}/overview", $token, $tenant)->assertOk();
        $this->assertSame(1, $this->asTenant($tenant, fn () => app(CourseAccess::class)->featureScope($tenant, $user, $course, 'smarttrainer')['limit']));
    }

    public function test_import_access_rules_command(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $file = tempnam(sys_get_temp_dir(), 'rules');
        file_put_contents($file, json_encode(['default_access' => 'logged_in', 'rules' => [
            ['tenant_slug' => $tenant->slug, 'course_code' => 'SRC', 'feature_code' => 'question_bank', 'access_type' => 'free_limited', 'limit_value' => 5],
            ['tenant_slug' => $tenant->slug, 'course_code' => 'SRC', 'feature_code' => 'exam_simulation', 'access_type' => 'free', 'source_key' => 'wp:exam'],
        ]]));

        try {
            $this->artisan('app:import-mobile-access-rules', ['file' => $file])->assertSuccessful();
            // Erneuter Import aktualisiert statt zu verdoppeln.
            $this->artisan('app:import-mobile-access-rules', ['file' => $file])->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $rules = $this->onAdmin(fn () => CourseAccessRule::where('tenant_id', $tenant->id)->where('course_id', $course->id)->orderBy('feature_code')->get());
        $this->assertSame(['exam_simulation', 'question_bank'], $rules->pluck('feature_code')->all());
        $this->assertSame(5, $rules->last()->limit_value);
        // Der WP-Wert ändert den Schalter nicht.
        $this->assertSame('entitlement', config('mobile_api.default_access'));

        $bad = tempnam(sys_get_temp_dir(), 'rules');
        file_put_contents($bad, json_encode(['rules' => [['tenant_slug' => $tenant->slug, 'course_code' => 'SRC', 'feature_code' => 'question_bank', 'access_type' => 'free', 'limit_value' => 3]]]));
        try {
            $this->artisan('app:import-mobile-access-rules', ['file' => $bad])->assertFailed();
        } finally {
            @unlink($bad);
        }
    }

    public function test_import_entitlements_command_maps_features_to_capabilities(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $file = tempnam(sys_get_temp_dir(), 'ent');
        file_put_contents($file, json_encode(['tenant_slug' => $tenant->slug, 'entitlements' => [
            ['email' => strtoupper($user->email), 'course_code' => 'SRC', 'feature_code' => 'exam_simulation', 'source_key' => 'wp:1', 'status' => 'active'],
            ['email' => $user->email, 'course_code' => 'FKN', 'feature_code' => 'all', 'source_key' => 'wp:2', 'status' => 'active'],
        ]]));

        try {
            $this->artisan('app:import-mobile-entitlements', ['file' => $file])->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $rows = $this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('source_type', 'wordpress')->orderBy('source_reference')->get());
        $this->assertSame([['exam'], null], $rows->pluck('capabilities')->all());

        $token = $this->apiLogin($user);
        $this->api('GET', '/courses/'.$this->existingCourse('FKN')->id.'/overview', $token, $tenant)->assertOk();
        $this->api('GET', '/courses/'.$this->existingCourse('SRC')->id.'/overview', $token, $tenant)->assertStatus(403);
    }

    /** Format-2-Zeile wie aus wp bootspruefung export entitlements (T46). */
    private function wpEntitlement(User $user, int $id, array $overrides = []): array
    {
        return array_merge([
            'email' => $user->email, 'course_code' => 'FKN', 'feature_code' => 'all', 'status' => 'active',
            'valid_from' => now()->subDays(10)->utc()->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => now()->addDays(300)->utc()->format('Y-m-d\TH:i:s\Z'),
            'user_registered' => '2024-03-01T08:00:00Z', 'source' => 'woocommerce', 'group_id' => null,
            'order_id' => 900000 + $id, 'source_key' => 'wp:entitlement:'.$id,
        ], $overrides);
    }

    private function formatTwo(Tenant $tenant, array $entitlements, array $simulator = []): array
    {
        return ['format_version' => 2, 'tenant_slug' => $tenant->slug, 'default_access' => 'entitlement',
            'entitlements' => $entitlements, 'device_simulation_entitlements' => $simulator];
    }

    /** Führt den Import mit einer temporären Exportdatei aus; $expect ergänzt Erwartungen an die Ausgabe. */
    private function importEntitlements(array $payload, array $options = [], ?callable $expect = null, bool $succeeds = true): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ent');
        file_put_contents($file, json_encode($payload));
        try {
            /** @var PendingCommand $command */
            $command = $this->artisan('app:import-mobile-entitlements', ['file' => $file] + $options);
            if ($expect) {
                $expect($command);
            }
            $succeeds ? $command->assertSuccessful() : $command->assertFailed();
            $command->run();
        } finally {
            @unlink($file);
        }
    }

    private function wpRows(Tenant $tenant): Collection
    {
        return $this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('source_type', 'wordpress')->orderBy('source_reference')->get());
    }

    public function test_import_entitlements_format_one_still_rejects_inactive_rows_and_unknown_versions(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->importEntitlements(['tenant_slug' => $tenant->slug, 'entitlements' => [
            ['email' => $user->email, 'course_code' => 'FKN', 'feature_code' => 'all', 'source_key' => 'wp:1', 'status' => 'active'],
            ['email' => $user->email, 'course_code' => 'SRC', 'feature_code' => 'all', 'source_key' => 'wp:2', 'status' => 'suspended'],
        ]], [], fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 2 (wp:2) ist nicht aktiv'), false);
        $this->assertCount(0, $this->wpRows($tenant), 'alles oder nichts: auch die gültige erste Zeile fehlt');

        $this->importEntitlements(['format_version' => 3, 'tenant_slug' => $tenant->slug, 'entitlements' => []], [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Unbekannte format_version 3'), false);

        $this->importEntitlements(['tenant_slug' => $tenant->slug, 'entitlements' => [
            ['email' => $user->email, 'course_code' => 'FKN', 'feature_code' => 'all', 'source_key' => 'wp:1', 'status' => 'active'],
        ]], [], fn (PendingCommand $c) => $c->expectsOutputToContain('1 aktive WP-Zugänge importiert/aktualisiert'));
        $rows = $this->wpRows($tenant);
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]->status);
        $this->assertNull($rows[0]->import_meta, 'Format 1 kennt keine Herkunft');
        $this->assertTrue($rows[0]->isCurrentlyActive(), 'ohne valid_from gilt der Zugang ab Kontoerstellung');
    }

    public function test_import_entitlements_format_two_keeps_suspended_access_locked(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->importEntitlements($this->formatTwo($tenant, [$this->wpEntitlement($user, 1, ['status' => 'suspended'])]), [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Davon gesperrt (suspended, nicht freigeschaltet): 1'));

        $row = $this->wpRows($tenant)->sole();
        $this->assertSame('suspended', $row->status);
        $this->assertFalse($row->isCurrentlyActive());
        $meta = $row->import_meta;
        ksort($meta);
        $this->assertSame(['group_id' => null, 'order_id' => 900001, 'source' => 'woocommerce', 'status' => 'suspended'], $meta);

        $token = $this->apiLogin($user);
        $fkn = $this->existingCourse('FKN');
        $this->api('GET', '/courses/'.$fkn->id.'/overview', $token, $tenant)->assertStatus(403);
        $this->assertSame('suspended', $this->courseEntry($tenant, $token, $fkn)['access']['status']);
    }

    public function test_import_entitlements_format_two_starts_later_access_only_at_valid_from(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $start = now()->addDays(30)->startOfSecond();

        $this->importEntitlements($this->formatTwo($tenant, [
            $this->wpEntitlement($user, 1, ['course_code' => 'SRC', 'valid_until' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z')]),
            $this->wpEntitlement($user, 2, ['valid_from' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'valid_until' => null]),
        ]), [], fn (PendingCommand $c) => $c->expectsOutputToContain('mit späterem Beginn (erst ab valid_from aktiv): 1'));

        $later = $this->wpRows($tenant)->firstWhere('source_reference', 'wp:entitlement:2');
        $this->assertSame('active', $later->status);
        $this->assertTrue($later->valid_from->equalTo($start), 'valid_from bleibt der spätere Beginn');
        $this->assertFalse($later->isCurrentlyActive());
        $active = fn () => $this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->currentlyActive()->pluck('source_reference')->all());
        $this->assertSame(['wp:entitlement:1'], $active());

        $fkn = $this->existingCourse('FKN');
        $token = $this->apiLogin($user);
        $this->api('GET', '/courses/'.$fkn->id.'/overview', $token, $tenant)->assertStatus(403);

        $this->travelTo($start->copy()->addHour());
        $this->assertSame(['wp:entitlement:2'], $active());
    }

    public function test_import_entitlements_format_two_maps_every_feature_to_capabilities(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $features = [
            'all' => null, 'question_bank' => ['smarttrainer'], 'learning_explanations' => ['smarttrainer'],
            'exam_simulation' => ['exam'], 'navigation_trainer' => ['navigation'], 'praxis_trainer' => ['praxis'],
            'video_course' => ['video'], 'radio_training' => ['funk'], 'downloads' => ['downloads'],
        ];
        $rows = [];
        $id = 0;
        foreach (array_keys($features) as $feature) {
            $rows[] = $this->wpEntitlement($user, ++$id, ['course_code' => 'SRC', 'feature_code' => $feature]);
        }

        $this->importEntitlements($this->formatTwo($tenant, $rows));

        $imported = $this->wpRows($tenant)->keyBy('source_reference');
        $id = 0;
        foreach ($features as $feature => $capabilities) {
            $this->assertSame($capabilities, $imported['wp:entitlement:'.++$id]->capabilities, $feature);
        }

        $this->importEntitlements($this->formatTwo($tenant, [$this->wpEntitlement($user, 99, ['feature_code' => 'device_simulation'])]), [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 1 (wp:entitlement:99) verwendet das nicht übertragbare Feature device_simulation'), false);
    }

    public function test_import_entitlements_format_two_imports_group_seats_as_individual_entitlements(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        // Konto ohne Mitgliedschaft in der Zielschule: wird als Lernender angelegt.
        $user = $this->createTenantUser($other, 'learner');

        $this->importEntitlements($this->formatTwo($tenant, [
            $this->wpEntitlement($user, 1, ['course_code' => 'SKN', 'feature_code' => 'question_bank', 'source' => 'group', 'group_id' => 4711, 'order_id' => 912345]),
            $this->wpEntitlement($user, 2, ['source' => 'voucher', 'order_id' => null]),
        ]), [], fn (PendingCommand $c) => $c
            ->expectsOutputToContain('Gruppenplätze: 1 wurden als einzelne Zugänge der Lernenden übernommen')
            ->expectsOutputToContain('1 fehlende Mitgliedschaften wurden als Lernende angelegt'));

        $group = $this->wpRows($tenant)->firstWhere('source_reference', 'wp:entitlement:1');
        $this->assertSame('wordpress', $group->source_type);
        $this->assertSame(['smarttrainer'], $group->capabilities);
        $this->assertSame('group', $group->import_meta['source']);
        $this->assertSame(4711, $group->import_meta['group_id']);
        $this->assertSame(912345, $group->import_meta['order_id']);
        $this->assertSame('voucher', $this->wpRows($tenant)->firstWhere('source_reference', 'wp:entitlement:2')->import_meta['source']);
        $this->assertSame(0, $this->onAdmin(fn () => LearningGroup::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count()), 'keine Lerngruppe angelegt');
        $this->assertSame('learner', $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role')));

        $this->importEntitlements($this->formatTwo($tenant, [$this->wpEntitlement($user, 3, ['source' => 'gift'])]), [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 1 (wp:entitlement:3) hat die unbekannte Herkunft "gift"'), false);
    }

    public function test_import_entitlements_format_two_skips_device_simulation_entitlements(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->importEntitlements($this->formatTwo($tenant, [$this->wpEntitlement($user, 1)], [
            $this->wpEntitlement($user, 2, ['course_code' => 'SRC', 'feature_code' => 'device_simulation']),
            $this->wpEntitlement($user, 3, ['course_code' => 'UBI', 'feature_code' => 'device_simulation', 'status' => 'suspended']),
        ]), [], fn (PendingCommand $c) => $c->expectsOutputToContain('Simulator-Zugänge (device_simulation): 2 übersprungen'));

        $this->assertSame(['wp:entitlement:1'], $this->wpRows($tenant)->pluck('source_reference')->all());
    }

    public function test_import_entitlements_format_two_is_idempotent_and_updates_on_reimport(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $row = $this->wpEntitlement($user, 1);

        $this->importEntitlements($this->formatTwo($tenant, [$row]), [], fn (PendingCommand $c) => $c->expectsOutputToContain('1 neu, 0 aktualisiert, 0 unverändert'));
        $first = $this->wpRows($tenant)->sole();
        $this->onAdmin(fn () => Entitlement::whereKey($first->id)->update(['expiry_reminded_at' => now()]));

        $this->importEntitlements($this->formatTwo($tenant, [$row]), [], fn (PendingCommand $c) => $c->expectsOutputToContain('0 neu, 0 aktualisiert, 1 unverändert'));
        $this->assertSame($first->id, $this->wpRows($tenant)->sole()->id);

        // In WP nach dem ersten Import gesperrt und verlängert.
        $until = now()->addDays(500)->startOfSecond();
        $suspended = ['status' => 'suspended', 'valid_until' => $until->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->importEntitlements($this->formatTwo($tenant, [array_merge($row, $suspended)]), [], fn (PendingCommand $c) => $c->expectsOutputToContain('0 neu, 1 aktualisiert, 0 unverändert'));
        $updated = $this->wpRows($tenant)->sole();
        $this->assertSame($first->id, $updated->id);
        $this->assertSame('suspended', $updated->status);
        $this->assertTrue($updated->valid_until->equalTo($until));
        $this->assertNull($updated->expiry_reminded_at, 'neues Ablaufdatum: Erinnerung wieder fällig');

        // WP hebt die eigene Sperre auf: der Import folgt.
        $this->importEntitlements($this->formatTwo($tenant, [array_merge($row, ['valid_until' => $suspended['valid_until']])]));
        $this->assertSame('active', $this->wpRows($tenant)->sole()->status);

        // In Laravel gesperrt bzw. widerrufen: der Import schaltet nicht wieder frei.
        foreach (['suspended', 'revoked'] as $local) {
            $this->onAdmin(fn () => Entitlement::whereKey($first->id)->update(['status' => $local]));
            $this->importEntitlements($this->formatTwo($tenant, [array_merge($row, ['valid_until' => null])]), [],
                fn (PendingCommand $c) => $c->expectsOutputToContain('1 Zugänge sind in Laravel gesperrt oder widerrufen und wurden nicht verändert'));
            $kept = $this->wpRows($tenant)->sole();
            $this->assertSame($local, $kept->status);
            $this->assertNotNull($kept->valid_until);
        }

        // Derselbe Quellschlüssel für ein anderes Konto bricht ab.
        $other = $this->createTenantUser($tenant, 'learner');
        $this->importEntitlements($this->formatTwo($tenant, [$this->wpEntitlement($other, 1)]), [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 1 (wp:entitlement:1): Der Quellschlüssel wurde bereits für ein anderes App-Konto importiert'), false);
        $this->importEntitlements($this->formatTwo($tenant, [$row, $row]), [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 2 (wp:entitlement:1): source_key steht bereits in Zugang 1'), false);
    }

    public function test_import_entitlements_dry_run_reports_without_writing(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($this->createTestTenant('Andere Schule'), 'learner');
        $payload = $this->formatTwo($tenant, [
            $this->wpEntitlement($user, 1),
            $this->wpEntitlement($user, 2, ['course_code' => 'SRC', 'feature_code' => 'exam_simulation', 'status' => 'suspended', 'source' => 'group', 'group_id' => 7]),
        ], [$this->wpEntitlement($user, 3, ['feature_code' => 'device_simulation'])]);

        $this->importEntitlements($payload, ['--dry-run' => true], fn (PendingCommand $c) => $c
            ->expectsOutputToContain('Probelauf (--dry-run): nichts gespeichert.')
            ->expectsOutputToContain('wp:entitlement:2')
            ->expectsOutputToContain('2 WP-Zugänge (Format 2): 2 neu, 0 aktualisiert, 0 unverändert; 1 fehlende Mitgliedschaften würden als Lernende angelegt.')
            ->expectsOutputToContain('Gruppenplätze: 1 würden als einzelne Zugänge')
            ->expectsOutputToContain('Simulator-Zugänge (device_simulation): 1 übersprungen'));

        $this->assertCount(0, $this->wpRows($tenant));
        $this->assertFalse($this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()));

        $this->importEntitlements($payload);
        $payload['entitlements'][0]['status'] = 'suspended';
        $this->importEntitlements($payload, ['--dry-run' => true], fn (PendingCommand $c) => $c
            ->expectsOutputToContain('aktualisieren')
            ->expectsOutputToContain('0 neu, 1 aktualisiert, 1 unverändert'));
        $this->assertSame('active', $this->wpRows($tenant)->firstWhere('source_reference', 'wp:entitlement:1')->status);

        // Probelauf prüft dieselben Regeln: unbekannter Kurs mit Zeilennummer und Quellschlüssel.
        $payload['entitlements'][] = $this->wpEntitlement($user, 4, ['course_code' => 'GIBTSNICHT']);
        $this->importEntitlements($payload, ['--dry-run' => true],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 3 (wp:entitlement:4): Kurs GIBTSNICHT fehlt im Original.'), false);
        $payload['entitlements'][2] = $this->wpEntitlement($user, 4, ['email' => 'niemand-'.uniqid().'@example.test']);
        $this->importEntitlements($payload, [],
            fn (PendingCommand $c) => $c->expectsOutputToContain('Zugang 3 (wp:entitlement:4): Für niemand-'), false);
        $this->assertSame('active', $this->wpRows($tenant)->firstWhere('source_reference', 'wp:entitlement:1')->status, 'abgebrochener Lauf ändert nichts');
    }

    public function test_entitlement_capabilities_are_constrained_in_the_database(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->expectException(QueryException::class);
        $this->partialEntitlement($tenant, $user, $this->existingCourse('SRC'), ['everything']);
    }
}
