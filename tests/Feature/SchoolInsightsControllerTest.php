<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\ContentQuestionRevision;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Statistik und Protokoll der Bootsschule (Admin\SchoolInsightsController):
 * Monatsgrenzen in deutscher Zeit (inkl. Sommer-/Winterzeit), leere Schule
 * und strikte Trennung von den Daten anderer Schulen.
 */
class SchoolInsightsControllerTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        // Feste Uhr: 13.11.2026 (Winterzeit; Umstellung war am 25.10.2026).
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00', 'Europe/Berlin'));
    }

    /** Zeitpunkt in deutscher Zeit als UTC-Zeitstempel für die DB. */
    private static function berlin(string $local): string
    {
        return Carbon::parse($local, 'Europe/Berlin')->utc()->format('Y-m-d H:i:sP');
    }

    private function attempt(Tenant $tenant, User $user, string $berlinTime): void
    {
        $this->onAdmin(function () use ($tenant, $user, $berlinTime) {
            $revision = ContentQuestionRevision::where('editorial_status', 'published')->firstOrFail();
            Attempt::forceCreate([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $revision->question_id, 'revision_id' => $revision->id,
                'correct' => true, 'context' => 'smarttrainer', 'created_at' => self::berlin($berlinTime),
            ]);
        });
    }

    private function examSession(Tenant $tenant, User $user, bool $passed, string $berlinTime): void
    {
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => ExamSession::forceCreate([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'rule_set_id' => ExamRuleSet::where('course_id', $course->id)->value('id'),
            'status' => 'evaluated', 'passed' => $passed, 'score' => $passed ? 1 : 0,
            'started_at' => self::berlin($berlinTime), 'submitted_at' => self::berlin($berlinTime), 'created_at' => self::berlin($berlinTime),
        ]));
    }

    private function audit(Tenant $tenant, User $actor, string $action, string $berlinTime): void
    {
        $this->onAdmin(fn () => DB::table('audit_log')->insert([
            'tenant_id' => $tenant->id, 'actor_user_id' => $actor->id, 'action' => $action,
            'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'created_at' => self::berlin($berlinTime),
        ]));
    }

    /** @return array<string, int> Monat (Y-m) => aktive Lernende */
    private function monthly($response): array
    {
        $months = collect(range(5, 0))->map(fn ($i) => now('Europe/Berlin')->startOfMonth()->subMonths($i)->format('Y-m'));

        return $months->combine(collect($response->viewData('monthly'))->pluck('learners'))->all();
    }

    public function test_activity_is_assigned_to_the_german_calendar_month(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $october = $this->createTenantUser($tenant, 'learner');
        $november = $this->createTenantUser($tenant, 'learner');
        $june = $this->createTenantUser($tenant, 'learner');
        $tooEarly = $this->createTenantUser($tenant, 'learner');
        foreach ([$october, $november, $june, $tooEarly] as $learner) {
            $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        }

        // 31.10. 23:30 (MEZ) = 22:30 UTC -> Oktober; 01.11. 00:10 (MEZ) = 23:10 UTC am 31.10. -> November.
        $this->attempt($tenant, $october, '2026-10-31 23:30:00');
        $this->attempt($tenant, $november, '2026-11-01 00:10:00');
        // Kurz nach der Umstellung (25.10. 02:30 MEZ, eine Stunde nach 02:30 MESZ) bleibt Oktober.
        $this->attempt($tenant, $october, '2026-10-25 02:30:00');
        // Fensterbeginn in Sommerzeit: 01.06. 00:10 (MESZ) = 31.05. 22:10 UTC zählt zum Juni,
        // 31.05. 23:50 (MESZ) liegt vor dem Fenster.
        $this->examSession($tenant, $june, true, '2026-06-01 00:10:00');
        $this->attempt($tenant, $tooEarly, '2026-05-31 23:50:00');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin/statistics')->assertOk();

        $this->assertSame(['2026-06' => 1, '2026-07' => 0, '2026-08' => 0, '2026-09' => 0, '2026-10' => 1, '2026-11' => 1], $this->monthly($response));
        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Okt 2026', 'Nov 2026'], collect($response->viewData('monthly'))->pluck('label')->all());
        // 30-Tage-Fenster: 14.10. 12:00 bis jetzt -> Oktober- und November-Lerner.
        $response->assertViewHas('activeLearners30', 2);
        $response->assertViewHas('answers30', 3);
        $response->assertViewHas('withAccess', 4);
    }

    public function test_audit_date_filter_uses_german_calendar_days(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->audit($tenant, $admin, 'test.oktober', '2026-10-31 23:30:00');
        $this->audit($tenant, $admin, 'test.november', '2026-11-01 00:10:00');

        $actions = fn (array $query) => $this->actingAsInTenant($admin, $tenant)->get('/admin/audit?'.http_build_query($query))->assertOk()
            ->viewData('entries')->getCollection()->pluck('action')->sort()->values()->all();

        $this->assertSame(['test.oktober'], $actions(['to' => '2026-10-31']));
        $this->assertSame(['test.november'], $actions(['from' => '2026-11-01']));
        $this->assertSame(['test.november', 'test.oktober'], $actions(['from' => '2026-10-31', 'to' => '2026-11-01']));
        // Ungültige Daten werden ignoriert statt zu scheitern.
        $this->assertSame(['test.november', 'test.oktober'], $actions(['from' => '2026-02-31', 'to' => ['x']]));
    }

    public function test_empty_school_renders_zeros(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin/statistics')->assertOk()
            ->assertSee('Noch keine abgeschlossenen Prüfungssimulationen.');

        $response->assertViewHas('withAccess', 0)->assertViewHas('activeLearners30', 0)->assertViewHas('answers30', 0);
        $this->assertSame([0, 0, 0, 0, 0, 0], collect($response->viewData('monthly'))->pluck('learners')->all());
        $this->assertCount(0, $response->viewData('exams'));
        $this->assertCount(0, $response->viewData('courses'));

        $this->actingAsInTenant($admin, $tenant)->get('/admin/audit')->assertOk()->assertSee('Keine Einträge gefunden');
    }

    public function test_other_schools_data_never_appears(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $admin = $this->createTenantUser($tenant, 'admin');
        $course = $this->existingCourse('SRC');

        $own = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $own, $course);
        $this->attempt($tenant, $own, '2026-11-10 10:00:00');
        $this->examSession($tenant, $own, false, '2026-11-10 11:00:00');
        // Zweiter laufender Zugang derselben Person zum selben Kurs: weiterhin EIN Teilnehmer.
        $this->grantEntitlement($tenant, $own, $course);
        // Probelauf eines Admins zählt nicht als Teilnehmer-Prüfung.
        $this->examSession($tenant, $admin, true, '2026-11-10 12:00:00');

        // Fremde Lernende und dieselbe Person als Mitglied der anderen Schule.
        $foreign = $this->createTenantUser($other, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $own->id, 'role' => 'learner', 'status' => 'active']));
        foreach ([$foreign, $own] as $learner) {
            $this->grantEntitlement($other, $learner, $course);
            $this->attempt($other, $learner, '2026-11-11 10:00:00');
            $this->attempt($other, $learner, '2026-09-11 10:00:00');
            $this->examSession($other, $learner, true, '2026-11-11 11:00:00');
            $questionId = $this->onAdmin(fn () => ContentQuestionRevision::where('editorial_status', 'published')->value('question_id'));
            $this->onAdmin(fn () => Progress::create([
                'tenant_id' => $other->id, 'user_id' => $learner->id, 'question_id' => $questionId, 'learning_state' => 'gefestigt',
                'mastery_score' => 1, 'attempt_count' => 1, 'correct_count' => 1,
            ]));
        }
        $otherAdmin = $this->createTenantUser($other, 'admin', ['email' => 'fremd-'.uniqid().'@example.test']);
        $this->audit($other, $otherAdmin, 'fremd.aktion', '2026-11-12 09:00:00');
        $this->audit($tenant, $admin, 'eigen.aktion', '2026-11-12 09:00:00');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin/statistics')->assertOk();
        $response->assertViewHas('withAccess', 1)->assertViewHas('activeLearners30', 1)->assertViewHas('answers30', 1);
        $this->assertSame(['2026-06' => 0, '2026-07' => 0, '2026-08' => 0, '2026-09' => 0, '2026-10' => 0, '2026-11' => 1], $this->monthly($response));
        $exams = $response->viewData('exams');
        $this->assertCount(1, $exams);
        $this->assertSame(1, (int) $exams->first()->total);
        $this->assertSame(0, (int) $exams->first()->passed_count);
        $courses = $response->viewData('courses');
        $this->assertSame([['name' => $course->name, 'learners' => 1, 'average' => 0, 'ready' => 0]], $courses->all());

        $audit = $this->actingAsInTenant($admin, $tenant)->get('/admin/audit')->assertOk();
        $this->assertSame(['eigen.aktion'], $audit->viewData('entries')->getCollection()->pluck('action')->all());
        $byActor = $this->actingAsInTenant($admin, $tenant)->get('/admin/audit?actor=fremd-')->assertOk();
        $this->assertSame([], $byActor->viewData('entries')->getCollection()->pluck('action')->all());
        $this->assertSame(1, $this->onAdmin(fn () => AuditLog::where('tenant_id', $other->id)->where('action', 'fremd.aktion')->count()));
    }
}
