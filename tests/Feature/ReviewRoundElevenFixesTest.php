<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\Product;
use App\Models\Progress;
use App\Models\User;
use App\Services\AccountSetupLink;
use App\Services\MasteryCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 11. */
class ReviewRoundElevenFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Eingeladenes, nie aktiviertes Konto, angelegt vor 100 Tagen. */
    private function oldInvitedAccount(): array
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $this->onAdmin(function () use ($user, $tenant) {
            DB::table('app_user')->where('id', $user->id)->update(['created_at' => now()->subDays(100)]);
            DB::table('tenant_user')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->update(['created_at' => now()->subDays(100)]);
        });

        return [$tenant, $user];
    }

    private function userExists(User $user): bool
    {
        return $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->exists());
    }

    public function test_an_old_unclaimed_account_is_still_pruned(): void
    {
        [, $user] = $this->oldInvitedAccount();

        Artisan::call('app:prune-personal-data');

        $this->assertFalse($this->userExists($user));
    }

    public function test_a_re_sent_invitation_link_protects_the_account_for_the_full_period(): void
    {
        [$tenant, $user] = $this->oldInvitedAccount();
        $this->travel(-20)->days();
        $this->onAdmin(fn () => app(AccountSetupLink::class)->url($user, $tenant));
        $this->travelBack();

        Artisan::call('app:prune-personal-data');

        $this->assertTrue($this->userExists($user), 'Ein vor 20 Tagen neu verschickter Link zählt als neue Frist.');
    }

    public function test_a_recent_membership_at_another_school_protects_an_unclaimed_account(): void
    {
        [, $user] = $this->oldInvitedAccount();
        $other = $this->createTestTenant('Andere Schule');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert([
            'tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active',
        ]));

        Artisan::call('app:prune-personal-data');

        $this->assertTrue($this->userExists($user));
    }

    public function test_an_unclaimed_account_with_a_product_purchase_is_kept(): void
    {
        [$tenant, $user] = $this->oldInvitedAccount();
        $this->onAdmin(fn () => DB::table('product_purchase')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'product_id' => Product::query()->value('id'),
        ]));

        Artisan::call('app:prune-personal-data');

        $this->assertTrue($this->userExists($user));
    }

    public function test_a_mastered_question_does_not_fall_back_after_another_quick_correct_answer(): void
    {
        $progress = new Progress;
        $progress->fill(['attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu']);
        $calculator = new MasteryCalculator;
        $t = Carbon::parse('2026-05-01 10:00');

        $calculator->apply($progress, false, $t);
        $calculator->apply($progress, true, $t->copy()->addMinutes(1));
        $calculator->apply($progress, true, $t->copy()->addMinutes(2));
        $calculator->apply($progress, true, $t->copy()->addMinutes(16));
        $this->assertSame('gefestigt', $progress->learning_state);

        $calculator->apply($progress, true, $t->copy()->addMinutes(19));
        $this->assertSame('gefestigt', $progress->learning_state);
        $this->assertSame(4, $progress->current_streak);
    }

    public function test_the_third_quick_correct_answer_after_a_mistake_still_does_not_master(): void
    {
        $progress = new Progress;
        $progress->fill(['attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu']);
        $calculator = new MasteryCalculator;
        $t = Carbon::parse('2026-05-01 10:00');

        foreach ([false, true, true, true] as $i => $correct) {
            $calculator->apply($progress, $correct, $t->copy()->addMinutes($i));
        }

        $this->assertNotSame('gefestigt', $progress->learning_state);
    }

    public function test_the_hourly_expiry_job_waits_for_the_navigation_submit_grace(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 2)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        $sq = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->first());
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $sq->position, 'answer_id' => $sq->revision->answers->first()->id,
        ]);

        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 30));
        Artisan::call('app:expire-abandoned-exam-sessions');
        $this->assertSame('running', $this->onAdmin(fn () => ExamSession::find($session->id)->status));

        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 300));
        Artisan::call('app:expire-abandoned-exam-sessions');
        $this->assertNotSame('running', $this->onAdmin(fn () => ExamSession::find($session->id)->status));
    }
}
