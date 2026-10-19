<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Services\EntitlementService;
use App\Services\LearningService;
use App\Support\TenantContext;
use App\Support\VideoCourseLessons;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Zu den Fragen"-Quiz nach einem Video (siehe video_lesson_question-Pivot,
 * im Superadmin über VideoLessonController gepflegt). Antworten laufen über
 * dieselbe LearningService::recordAttempt()-Learning-Loop wie Smart-Learning
 * und Prüfungssimulation, damit Fortschritt/Mastery konsistent einfließen.
 */
class VideoQuizController extends Controller
{
    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, VideoLesson $lesson, int $position): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);
        abort_unless($lesson->module->course_id === $course->id, 404);

        $questions = $lesson->questions;
        abort_if($questions->isEmpty(), 404);
        abort_unless($position >= 1 && $position <= $questions->count(), 404);

        $revision = $questions[$position - 1]->publishedRevision();
        abort_if(! $revision, 404, 'Für diese Frage liegt keine veröffentlichte Fassung vor.');
        $revision->load('answers', 'media');

        $moduleIds = $this->parseModuleIds($request);

        return view('video.quiz', [
            'course' => $course,
            'lesson' => $lesson,
            'moduleIds' => $moduleIds,
            'position' => $position,
            'total' => $questions->count(),
            'revision' => $revision,
            'answered' => false,
            'correct' => null,
            'selectedAnswerId' => null,
            'next' => $this->nextLesson($course, $lesson, $moduleIds),
        ]);
    }

    public function answer(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, LearningService $learningService, CourseDefinition $course, VideoLesson $lesson, int $position): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);
        abort_unless($lesson->module->course_id === $course->id, 404);

        $questions = $lesson->questions;
        abort_unless($position >= 1 && $position <= $questions->count(), 404);

        $revision = $questions[$position - 1]->publishedRevision();
        abort_if(! $revision, 404);

        $validated = $request->validate([
            'answer_id' => ['nullable', 'uuid'],
        ]);

        $selectedAnswer = null;
        if ($validated['answer_id'] ?? null) {
            $selectedAnswer = ContentAnswer::where('id', $validated['answer_id'])
                ->where('revision_id', $revision->id)
                ->firstOrFail();
        }

        $attempt = $learningService->recordAttempt(
            $tenant, $user, $revision, $selectedAnswer, 'video_quiz', $course, null
        );

        $revision->load('answers', 'media');

        $moduleIds = $this->parseModuleIds($request);

        return view('video.quiz', [
            'course' => $course,
            'lesson' => $lesson,
            'moduleIds' => $moduleIds,
            'position' => $position,
            'total' => $questions->count(),
            'revision' => $revision,
            'answered' => true,
            'correct' => $attempt->correct,
            'selectedAnswerId' => $selectedAnswer?->id,
            'next' => $this->nextLesson($course, $lesson, $moduleIds),
        ]);
    }

    private function parseModuleIds(Request $request): ?array
    {
        $raw = $request->query('kapitel');

        return $raw ? array_values(array_filter(explode(',', $raw))) : null;
    }

    private function nextLesson(CourseDefinition $course, VideoLesson $lesson, ?array $moduleIds): ?VideoLesson
    {
        $lessons = VideoCourseLessons::ordered($course, $moduleIds);
        $currentIndex = $lessons->search(fn ($l) => $l->id === $lesson->id);

        return $currentIndex === false ? null : $lessons->get($currentIndex + 1);
    }
}
