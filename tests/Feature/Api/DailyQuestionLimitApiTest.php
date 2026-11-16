<?php

namespace Tests\Feature\Api;

use App\Api\DailyQuestionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tagesgrenze ausgelieferter Fragen je Konto (Schutz gegen das Abgreifen
 * des Fragenkatalogs über die App): Lernmodus zählt je Frage, ein neu
 * gestarteter Prüfungsbogen mit seiner Fragenzahl; darüber 429
 * DAILY_LIMIT_REACHED, 0 schaltet ab.
 */
class DailyQuestionLimitApiTest extends MobileApiTestCase
{
    private array $limitedUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->limitedUsers as $user) {
            RateLimiter::clear(DailyQuestionLimit::key($user));
        }
        parent::tearDown();
    }

    private function learner(): array
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $this->limitedUsers[] = $user;

        return [$tenant, $user, $course, $this->apiLogin($user)];
    }

    public function test_trainer_and_exam_start_stop_at_the_daily_limit(): void
    {
        config(['mobile_api.daily_question_limit' => 3]);
        [$tenant, $user, $course, $token] = $this->learner();

        foreach (range(1, 3) as $i) {
            $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk();
        }

        $blocked = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)
            ->assertStatus(429)->assertJsonPath('code', 'DAILY_LIMIT_REACHED');
        $this->assertStringContainsString('Tageslimit', $blocked->json('detail'));
        $this->assertStringContainsString('Mitternacht', $blocked->json('detail'));
        $retryAfter = (int) $blocked->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(86400 + 3600, $retryAfter);

        $paperId = $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->json('data.0.id');
        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])
            ->assertStatus(429)->assertJsonPath('code', 'DAILY_LIMIT_REACHED');

        // Der Zähler hängt am Konto und am Kalendertag in Europe/Berlin.
        $this->assertStringEndsWith(':'.Carbon::now('Europe/Berlin')->format('Y-m-d'), DailyQuestionLimit::key($user));
        $this->assertStringContainsString($user->id, DailyQuestionLimit::key($user));

        // 0 = aus.
        config(['mobile_api.daily_question_limit' => 0]);
        $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk();
    }

    public function test_a_new_exam_counts_its_questions_and_resuming_it_does_not(): void
    {
        config(['mobile_api.daily_question_limit' => 1000]);
        [$tenant, $user, $course, $token] = $this->learner();

        $paperId = $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->json('data.0.id');
        $started = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated();
        $questionCount = $started->json('data.question_count');
        $this->assertGreaterThan(0, $questionCount);
        $this->assertSame($questionCount, RateLimiter::attempts(DailyQuestionLimit::key($user)));

        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertOk();
        $this->assertSame($questionCount, RateLimiter::attempts(DailyQuestionLimit::key($user)));
    }

    public function test_the_default_limit_is_configurable_via_env(): void
    {
        $this->assertSame(2000, (int) (require config_path('mobile_api.php'))['daily_question_limit']);
        $this->assertNotSame(DailyQuestionLimit::key((new User)->forceFill(['id' => 'a'])), DailyQuestionLimit::key((new User)->forceFill(['id' => 'b'])));
    }
}
