<?php

namespace Tests\Perf;

use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Messsonde (kein Regressionstest, liegt bewusst außerhalb der Testsuites
 * aus phpunit.xml): legt eine große Bootsschule an (1200 Lernende mit
 * Freischaltungen, Fortschritt/360 000 Versuche/4500 Prüfungen für 300,
 * 20 Lerngruppen, 5000 Coupons, 50 000 Audit-Log-Zeilen), misst je Seite Abfragen, Zeit und
 * Spitzenspeicher und räumt danach wieder auf.
 *
 * Aufruf: vendor/bin/phpunit tests/Perf/LargeSchoolProbeTest.php
 * Ergebnis: storage/logs/perf-probe.txt (bzw. PERF_PROBE_OUT).
 */
class LargeSchoolProbeTest extends TestCase
{
    use InteractsWithTenants;

    private const LEARNERS = 1200;

    private const ACTIVE_LEARNERS = 300;

    private const GROUPS = 20;

    private const COUPONS = 5000;

    /** 300 Lernende x 60 Fragen x Runden Versuche (20 = 360 000). */
    private const ATTEMPT_ROUNDS = 20;

    private const AUDIT_ROWS = 50000;

    private ?string $tenantId = null;

    private bool $recording = false;

    private array $queries = [];

    protected function tearDown(): void
    {
        // PERF_PROBE_KEEP=1: Daten für EXPLAIN-Experimente stehen lassen (der nächste Lauf räumt sie vorher weg).
        if (! env('PERF_PROBE_KEEP')) {
            $this->removeProbeData();
        }
        parent::tearDown();
    }

    /** Alle Daten der Sonde (auch eines früheren, stehen gelassenen Laufs). */
    private function removeProbeData(): void
    {
        $this->onAdmin(function () {
            $tenantIds = DB::table('tenant')->where('slug', 'like', 'perf-%')->pluck('id');
            $userIds = DB::table('app_user')->where('email', 'like', '%@perf.test')->select('id');
            DB::table('coupon')->where('code', 'like', 'PERF%')->delete();
            DB::table('audit_log')->whereIn('tenant_id', $tenantIds)->delete();
            DB::table('api_device_session')->whereIn('user_id', $userIds)->delete();
            DB::table('api_idempotency_key')->whereIn('user_id', $userIds)->delete();
            DB::table('tenant')->whereIn('id', $tenantIds)->delete();
            DB::table('app_user')->where('email', 'like', '%@perf.test')->delete();
        });
    }

    public function test_probe_large_school(): void
    {
        ini_set('memory_limit', '2G');
        $this->removeProbeData();
        $fixture = $this->seedLargeSchool();
        DB::listen(function ($query) {
            // RESET app.* stammt aus den terminating()-Callbacks, die sich im
            // Test (eine App-Instanz für alle Requests) aufsummieren -- in
            // Produktion genau zwei je Request, deshalb hier nicht gezählt.
            if ($this->recording && ! str_starts_with($query->sql, 'RESET app.')) {
                $this->queries[] = ['query' => '['.$query->connectionName.'] '.$query->sql, 'time' => $query->time, 'sql' => $query->sql, 'bindings' => $query->bindings, 'connection' => $query->connectionName];
            }
        });
        $tenant = $fixture['tenant'];

        $results = [];
        $measure = function (string $label, callable $call) use (&$results) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $this->recording = true;
            $this->queries = [];
            $start = microtime(true);
            $status = $call();
            $elapsed = (microtime(true) - $start) * 1000;
            $this->recording = false;
            $queries = $this->queries;
            $peak = memory_get_peak_usage() - $base;
            $dbTime = array_sum(array_column($queries, 'time'));
            $grouped = collect($queries)->groupBy('query')->map(fn ($g) => ['n' => $g->count(), 't' => $g->sum('time')])->sortByDesc('t');
            $results[] = [
                'label' => $label, 'status' => $status, 'queries' => count($queries), 'ms' => round($elapsed),
                'db_ms' => round($dbTime), 'peak_mb' => round($peak / 1048576, 1),
                'top' => $grouped->take((int) env('PERF_PROBE_TOP', 6))->map(fn ($v, $sql) => sprintf('%4dx %7.1fms  %s', $v['n'], $v['t'], Str::limit($sql, 220)))->values()->all(),
                'explain' => env('PERF_PROBE_EXPLAIN') ? $this->explainSlowest($queries) : [],
                'repeated' => $grouped->filter(fn ($v) => $v['n'] >= 5)->map(fn ($v, $sql) => sprintf('%4dx  %s', $v['n'], Str::limit($sql, 220)))->values()->all(),
            ];
        };

        $owner = $fixture['owner'];
        $web = function (User $user, string $uri) use ($tenant) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAsInTenant($user, $tenant)->get($uri);

            if ($dump = env('PERF_PROBE_DUMP')) {
                file_put_contents($dump.'/'.Str::slug($uri).'.html', (string) $response->getContent());
            }

            return $response->getStatusCode().' '.round(strlen((string) $response->getContent()) / 1024).'KB';
        };

        $measure('/admin', fn () => $web($owner, '/admin'));
        $measure('/admin?participants_page=10', fn () => $web($owner, '/admin?participants_page=10&coupon_page=5'));
        $measure('/admin?participant search', fn () => $web($owner, '/admin?search=Lerner+1'));
        $measure('/admin/participants/{active learner}', fn () => $web($owner, '/admin/participants/'.$fixture['activeLearner']->id));
        $measure('/admin/participants/{group owner}', fn () => $web($owner, '/admin/participants/'.$fixture['groupOwner']->id));
        $measure('/admin/inactive', fn () => $web($owner, '/admin/inactive'));
        $measure('/admin/statistics', fn () => $web($owner, '/admin/statistics'));
        $measure('/admin/audit', fn () => $web($owner, '/admin/audit'));
        $measure('/admin/renewals', fn () => $web($owner, '/admin/renewals'));
        $measure('/admin/announcements', fn () => $web($owner, '/admin/announcements'));
        $measure('/admin/learning-groups/{id}', fn () => $web($owner, '/admin/learning-groups/'.$fixture['group']->id));
        $measure('/admin (instructor)', fn () => $web($fixture['instructor'], '/admin'));

        $superadmin = $this->createSuperAdmin(['email' => Str::uuid().'@perf.test']);
        $sa = function (string $uri) use ($superadmin) {
            $this->app['auth']->forgetGuards();
            $this->withSession([]);
            $response = $this->actingAs($superadmin)->get($uri);

            return $response->getStatusCode().' '.round(strlen((string) $response->getContent()) / 1024).'KB';
        };
        $measure('/superadmin', fn () => $sa('/superadmin'));
        $measure('/superadmin/tenants', fn () => $sa('/superadmin/tenants'));
        $measure('/superadmin/tenants/{id}', fn () => $sa('/superadmin/tenants/'.$tenant->id));
        $measure('/superadmin/users', fn () => $sa('/superadmin/users'));
        $measure('/superadmin/users?search', fn () => $sa('/superadmin/users?search=perf'));
        $measure('/superadmin/users/{active learner}', fn () => $sa('/superadmin/users/'.$fixture['activeLearner']->id));
        $measure('/superadmin/audit', fn () => $sa('/superadmin/audit'));

        $learner = $fixture['activeLearner'];
        $measure('/dashboard (learner)', fn () => $web($learner, '/dashboard'));
        $measure('/courses/{SBF-SEE} (learner)', fn () => $web($learner, '/courses/'.$fixture['course']->id));
        $measure('/courses/{SBF-SEE}/progress', fn () => $web($learner, '/courses/'.$fixture['course']->id.'/progress'));

        $this->app['auth']->forgetGuards();
        $token = $this->apiToken($learner);
        $ownerToken = $this->apiToken($fixture['groupOwner']);
        $api = function (string $token, string $uri) use ($tenant) {
            $this->app['auth']->forgetGuards();
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Tenant-Slug' => $tenant->slug])->getJson('/api/v1'.$uri);

            return $response->getStatusCode().' '.round(strlen((string) $response->getContent()) / 1024).'KB';
        };
        $measure('API /me', fn () => $api($token, '/me'));
        $measure('API /courses (kalter Cache)', fn () => $api($token, '/courses?per_page=100'));
        $measure('API /courses (warmer Cache)', fn () => $api($token, '/courses?per_page=100'));
        $measure('API /courses/{id}', fn () => $api($token, '/courses/'.$fixture['course']->id));
        $measure('API /courses/{id}/progress', fn () => $api($token, '/courses/'.$fixture['course']->id.'/progress'));
        $measure('API /courses/{id}/trainer/next', fn () => $api($token, '/courses/'.$fixture['course']->id.'/trainer/next'));
        $measure('API /me/groups (member)', fn () => $api($token, '/me/groups'));
        $measure('API /me/groups (group admin)', fn () => $api($ownerToken, '/me/groups'));
        $measure('API /me/groups/{id} (group admin)', fn () => $api($ownerToken, '/me/groups/'.$fixture['group']->id));

        $out = env('PERF_PROBE_OUT', storage_path('logs/perf-probe.txt'));
        $lines = [sprintf('%-40s %-12s %7s %8s %7s %8s', 'Seite', 'Status', 'Queries', 'Zeit ms', 'DB ms', 'Peak MB')];
        foreach ($results as $r) {
            $lines[] = sprintf('%-40s %-12s %7d %8d %7d %8.1f', $r['label'], $r['status'], $r['queries'], $r['ms'], $r['db_ms'], $r['peak_mb']);
        }
        $lines[] = '';
        foreach ($results as $r) {
            $lines[] = '== '.$r['label'];
            foreach ($r['top'] as $t) {
                $lines[] = '  top '.$t;
            }
            foreach ($r['repeated'] as $t) {
                $lines[] = '  rep '.$t;
            }
            foreach ($r['explain'] as $t) {
                $lines[] = $t;
            }
        }
        file_put_contents($out, implode("\n", $lines)."\n");
        fwrite(STDERR, implode("\n", array_slice($lines, 0, count($results) + 1))."\n");
        $this->assertNotEmpty($results);
    }

    /** EXPLAIN ANALYZE der langsamsten (>= 3 ms) Abfragen einer Seite, mit Tenant-Kontext wie im Request. */
    private function explainSlowest(array $queries): array
    {
        $out = [];
        $slow = collect($queries)->filter(fn ($q) => $q['time'] >= 3 && preg_match('/^\s*select/i', $q['sql']))->sortByDesc('time')->unique('sql')->take(4);
        foreach ($slow as $q) {
            $connection = DB::connection($q['connection']);
            $connection->statement("SET app.current_tenant_id = '{$this->tenantId}'");
            try {
                $plan = $connection->select('explain (analyze, buffers) '.$q['sql'], $q['bindings']);
                $out[] = '  explain '.Str::limit($q['sql'], 160).' ['.$q['time'].'ms]';
                foreach ($plan as $row) {
                    $out[] = '     | '.Str::limit(array_values((array) $row)[0], 200);
                }
            } catch (\Throwable $e) {
                $out[] = '  explain failed: '.Str::limit($e->getMessage(), 200);
            } finally {
                $connection->statement('RESET app.current_tenant_id');
            }
        }

        return $out;
    }

    private function apiToken(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Perf']);
        $response->assertOk();

        return $response->json('data.access_token');
    }

    /** @return array<string, mixed> */
    private function seedLargeSchool(): array
    {
        return $this->onAdmin(function () {
            $tenant = Tenant::create(['slug' => 'perf-'.Str::lower(Str::random(8)), 'name' => 'Perf Bootsschule', 'status' => 'active']);
            $this->tenantId = $tenant->id;
            DB::table('tenant_branding')->insert(['tenant_id' => $tenant->id, 'primary_color' => '#123456']);
            $t = $tenant->id;
            $hash = bcrypt('password');

            $course = DB::table('course_definition')->whereNull('tenant_id')->where('code', 'SBF-SEE')->first();
            $src = DB::table('course_definition')->whereNull('tenant_id')->where('code', 'SRC')->first();
            $bin = DB::table('course_definition')->whereNull('tenant_id')->where('code', 'SBF-BIN-MOTOR')->first();

            // Nutzer + Mitgliedschaften per generate_series (schnell).
            DB::statement("insert into app_user (id, email, display_name, first_name, last_name, password, status, email_verified_at, age_confirmed_at, last_login_at, created_at, updated_at)
                select gen_random_uuid(), 'lerner'||i||'-'||substr(md5(random()::text),1,6)||'@perf.test', 'Lerner '||i, 'Lerner', 'Nr'||i, ?, 'active', now(), now(),
                       case when i % 3 = 0 then now() - (i % 90 || ' days')::interval else null end,
                       now() - ((i % 400) || ' days')::interval, now()
                from generate_series(1, ?) i", [$hash, self::LEARNERS]);
            $team = [];
            foreach (['owner', 'admin', 'instructor', 'instructor'] as $i => $role) {
                $team[$role.$i] = User::forceCreate(['email' => "team{$i}-".Str::random(6).'@perf.test', 'display_name' => "Team {$role} {$i}", 'password' => $hash,
                    'status' => 'active', 'email_verified_at' => now(), 'age_confirmed_at' => now()]);
                DB::table('tenant_user')->insert(['tenant_id' => $t, 'user_id' => $team[$role.$i]->id, 'role' => $role, 'status' => 'active']);
            }
            DB::statement("insert into tenant_user (tenant_id, user_id, role, status, created_at)
                select ?, id, 'learner', 'active', created_at from app_user where email like 'lerner%@perf.test'", [$t]);
            $learnerIds = DB::table('app_user')->where('email', 'like', 'lerner%@perf.test')->orderBy('created_at')->orderBy('id')->pluck('id')->all();
            DB::statement('create temp table perf_learner (user_id uuid, rn int primary key)');
            foreach (array_chunk($learnerIds, 500, true) as $chunk) {
                DB::table('perf_learner')->insert(array_map(fn ($id, $i) => ['user_id' => $id, 'rn' => $i], $chunk, array_keys($chunk)));
            }

            // Freischaltungen: alle SBF See, jeder zweite SRC, jeder fünfte Binnen (teils abgelaufen).
            DB::statement("insert into entitlement (tenant_id, user_id, course_id, status, source_type, valid_from, valid_until, created_at, updated_at)
                select ?, u.user_id, ?, 'active', 'manual', now() - interval '200 days', case when u.rn % 10 = 9 then now() - interval '5 days' else null end, now(), now()
                from perf_learner u", [$t, $course->id]);
            DB::statement("insert into entitlement (tenant_id, user_id, course_id, status, source_type, valid_from, created_at, updated_at)
                select ?, u.user_id, ?, 'active', 'manual', now() - interval '3 days', now(), now()
                from perf_learner u where u.rn % 2 = 0", [$t, $src->id]);
            DB::statement("insert into entitlement (tenant_id, user_id, course_id, status, source_type, valid_from, created_at, updated_at)
                select ?, u.user_id, ?, 'active', 'manual', now() - interval '30 days', now(), now()
                from perf_learner u where u.rn % 5 = 0", [$t, $bin->id]);
            // Instructor 2 betreut nur SRC.
            DB::table('instructor_course')->insert(['tenant_id' => $t, 'user_id' => $team['instructor2']->id, 'course_id' => $src->id]);

            // Fortschritt + Versuche für die ersten 300 Lernenden.
            $questions = DB::table('module_content')->join('course_module', 'course_module.module_id', '=', 'module_content.module_id')
                ->where('course_module.course_id', $course->id)->distinct()->pluck('module_content.question_id')->all();
            $active = array_slice($learnerIds, 0, self::ACTIVE_LEARNERS);
            DB::statement('create temp table perf_active (user_id uuid, rn int)');
            DB::table('perf_active')->insert(array_map(fn ($id, $i) => ['user_id' => $id, 'rn' => $i], $active, array_keys($active)));
            DB::statement('create temp table perf_q (question_id uuid, qn int)');
            DB::table('perf_q')->insert(array_map(fn ($id, $i) => ['question_id' => $id, 'qn' => $i], $questions, array_keys($questions)));
            DB::statement("insert into progress (tenant_id, user_id, question_id, attempt_count, correct_count, incorrect_count, current_streak, mastery_score, learning_state, first_seen_at, last_seen_at, updated_at)
                select ?, a.user_id, q.question_id, 3, 2, 1, 1, 0.6, case when (a.rn + q.qn) % 3 = 0 then 'gefestigt' else 'in_bearbeitung' end, now() - interval '20 days', now() - ((a.rn % 40) || ' days')::interval, now()
                from perf_active a join perf_q q on q.qn < 40 + (a.rn % 160)", [$t]);
            // Versuche: je aktivem Lernenden 60 Fragen x ATTEMPT_ROUNDS Runden über ~180 Tage verteilt.
            DB::statement('create temp table perf_rev as select distinct on (question_id) question_id, id from content_question_revision where editorial_status = \'published\' order by question_id, revision_no desc');
            DB::statement("insert into attempt (tenant_id, user_id, question_id, revision_id, course_id, correct, context, response_time_ms, created_at)
                select ?, a.user_id, q.question_id, r.id, ?, (a.rn + q.qn + g) % 4 <> 0, 'smarttrainer', 5000,
                       now() - (((a.rn * 7 + q.qn + g * 9) % (case when a.rn % 4 = 0 then 20 else 180 end)) || ' days')::interval - ((q.qn % 24) || ' hours')::interval
                from perf_active a join perf_q q on q.qn < 60 cross join generate_series(1, ?) g
                join perf_rev r on r.question_id = q.question_id", [$t, $course->id, self::ATTEMPT_ROUNDS]);
            DB::statement('drop table perf_rev');
            $ruleSet = DB::table('exam_rule_set')->where('course_id', $course->id)->value('id');
            $paper = DB::table('exam_paper')->where('course_id', $course->id)->value('id');
            if ($ruleSet) {
                DB::statement("insert into exam_session (tenant_id, user_id, course_id, rule_set_id, paper_id, status, started_at, submitted_at, score, passed, created_at)
                    select ?, a.user_id, ?, ?, case when a.rn % 2 = 0 then ?::uuid else null end, 'evaluated', now() - (((a.rn + g * 11) % 200) || ' days')::interval, now() - (((a.rn + g * 11) % 200) || ' days')::interval, 0.8, (a.rn + g) % 3 <> 0, now() - (((a.rn + g * 11) % 200) || ' days')::interval
                    from perf_active a cross join generate_series(1, 15) g", [$t, $course->id, $ruleSet, $paper]);
            }
            DB::statement('drop table perf_active');
            DB::statement('drop table perf_q');

            // Lerngruppen: 20 Gruppen à 15 aktive Mitglieder + 3 Einladungen (Lernende 301..).
            $groups = [];
            for ($g = 0; $g < self::GROUPS; $g++) {
                $ownerId = $learnerIds[400 + $g * 20];
                $group = LearningGroup::create([
                    'tenant_id' => $t, 'owner_user_id' => $ownerId, 'name' => 'Gruppe '.$g, 'status' => 'active', 'seats' => 30,
                    'scopes' => [['course_code' => 'SRC', 'feature' => 'course']], 'valid_from' => now()->subDays(10), 'valid_until' => now()->addYear(),
                    'join_code' => 'g'.bin2hex(random_bytes(16)),
                ]);
                $groups[] = $group;
                $rows = [];
                for ($m = 1; $m <= 15; $m++) {
                    $rows[] = ['tenant_id' => $t, 'group_id' => $group->id, 'user_id' => $learnerIds[400 + $g * 20 + $m], 'email' => null, 'status' => 'active', 'invite_token' => null, 'invited_at' => null, 'joined_at' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()];
                }
                for ($m = 1; $m <= 3; $m++) {
                    $rows[] = ['tenant_id' => $t, 'group_id' => $group->id, 'user_id' => null, 'email' => "einladung{$g}-{$m}@perf.test", 'status' => 'invited', 'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => now(), 'joined_at' => null, 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('learning_group_member')->insert($rows);
                DB::statement("insert into entitlement (tenant_id, user_id, course_id, status, source_type, source_reference, valid_from, valid_until, created_at, updated_at)
                    select ?, user_id, ?, 'active', 'learning_group', ?, now() - interval '5 days', now() + interval '1 year', now(), now() from learning_group_member where group_id = ? and status = 'active'", [$t, $src->id, $group->id, $group->id]);
            }

            // Coupons: 3000 frei, 1500 eingelöst, 500 entwertet/abgelaufen.
            DB::statement("insert into coupon (code, course_id, tenant_id, batch_label, redeemed_by_user_id, redeemed_tenant_id, redeemed_at, created_at, voided_at, expires_at)
                select 'PERF'||upper(substr(md5(random()::text||i),1,12)), case when i % 3 = 0 then ?::uuid else ?::uuid end, ?, 'Perf-Batch '||(i/500),
                       case when i > 3000 and i <= 4500 then (select user_id from perf_learner where rn = i % 1000) end,
                       case when i > 3000 and i <= 4500 then ?::uuid end,
                       case when i > 3000 and i <= 4500 then now() - ((i % 300) || ' days')::interval end,
                       now() - ((i % 500) || ' days')::interval,
                       case when i > 4500 and i % 2 = 0 then now() end,
                       case when i > 4500 and i % 2 = 1 then now() - interval '1 day' end
                from generate_series(1, ?) i", [$src->id, $course->id, $t, $t, self::COUPONS]);

            // Audit-Log.
            $ownerId = $team['owner0']->id;
            DB::statement("insert into audit_log (tenant_id, actor_user_id, action, entity_type, entity_id, before_data, after_data, created_at)
                select ?, ?, (array['participant.updated','entitlement.created','coupon.redeemed','participant.invited'])[1 + i % 4], 'app_user',
                       (select user_id from perf_learner where rn = i % 1000),
                       '{\"status\":\"active\"}'::jsonb, '{\"status\":\"suspended\"}'::jsonb, now() - ((i % 700) || ' hours')::interval
                from generate_series(1, ?) i", [$t, $ownerId, self::AUDIT_ROWS]);

            DB::statement('drop table perf_learner');
            // Wie in Produktion (Autovacuum): Statistiken und Visibility Map aktuell,
            // sonst wählt der Planer für frisch eingefügte Zeilen keine Index-Only-Scans.
            foreach (['app_user', 'tenant_user', 'entitlement', 'progress', 'attempt', 'exam_session', 'coupon', 'audit_log', 'learning_group_member'] as $table) {
                DB::statement("vacuum analyze {$table}");
            }

            return [
                'tenant' => $tenant,
                'owner' => $team['owner0'],
                'instructor' => $team['instructor2'],
                'course' => $course,
                'activeLearner' => User::find($learnerIds[5]),
                'groupOwner' => User::find($groups[0]->owner_user_id),
                'group' => $groups[0],
            ];
        });
    }
}
