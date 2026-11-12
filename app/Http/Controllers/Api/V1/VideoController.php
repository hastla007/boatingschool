<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use App\Models\VideoProgress;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Videokurs: Kapitel, Lektionen mit Schritt-Galerie und Wiedergabestand.
 * Platzhaltervideos (noch kein echtes Kursvideo) bleiben sichtbar, haben
 * aber keine abspielbare URL.
 */
class VideoController extends Controller
{
    /** Eigenständige Kapitel, die das Web als eigene Kachel zeigt (nicht im normalen Kursfluss). */
    private const STANDALONE_CHAPTERS = ['Knoten'];

    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId, 'video');
        $modules = $course->videoModules()->with('lessons.steps')->orderBy('sort_order')->get();
        $orderedLessons = $modules->flatMap(fn (VideoModule $module) => $module->lessons)->values();
        $allowedLessons = $this->access->limitItems($this->tenantContext->tenant(), $request->user(), $course, 'video', $orderedLessons);
        $allowedIds = $allowedLessons->pluck('id')->flip();
        $progress = $this->progressFor($request, $allowedLessons);

        return response()->json(['data' => $modules->values()->map(function (VideoModule $m, int $i) use ($progress, $allowedIds) {
            $lessons = $m->lessons->filter(fn (VideoLesson $lesson) => $allowedIds->has($lesson->id))->values();
            return [
                'id' => $m->id,
                'title' => $m->title,
                'order' => $i + 1,
                'standalone' => in_array($m->title, self::STANDALONE_CHAPTERS, true),
                'lessons' => $lessons->map(fn (VideoLesson $l) => $this->lesson($l, $progress->get($l->id), false))->values(),
            ];
        })->filter(fn (array $module) => $module['lessons']->isNotEmpty())->values()]);
    }

    public function show(Request $request, string $lessonId): JsonResponse
    {
        $lesson = $this->accessibleLesson($request, $lessonId);
        $lesson->load('steps');

        return response()->json(['data' => $this->lesson($lesson, $this->progressFor($request, collect([$lesson]))->get($lesson->id), true)]);
    }

    /** Wiedergabestand speichern; „abgeschlossen“ bleibt abgeschlossen. */
    public function progress(Request $request, string $lessonId): JsonResponse
    {
        $validated = $request->validate([
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'completed' => ['required', 'boolean'],
        ]);
        $lesson = $this->accessibleLesson($request, $lessonId);
        $key = ['tenant_id' => $this->tenantContext->id(), 'user_id' => $request->user()->id, 'video_lesson_id' => $lesson->id];
        $existing = VideoProgress::where($key)->first();
        $position = $lesson->duration_seconds ? min($validated['position_seconds'], (int) $lesson->duration_seconds) : $validated['position_seconds'];

        $row = VideoProgress::updateOrCreate($key, [
            'completed' => ($existing?->completed ?? false) || $validated['completed'],
            'last_position_seconds' => $position,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'position_seconds' => (int) $row->last_position_seconds,
            'completed' => (bool) $row->completed,
            'updated_at' => Present::time($row->updated_at),
        ]]);
    }

    /** Lektion laden; Zugang über den Kurs ihres Kapitels. */
    private function accessibleLesson(Request $request, string $lessonId): VideoLesson
    {
        $lesson = VideoLesson::with('module')->find($lessonId);
        if (! $lesson || ! $lesson->module) {
            throw ApiProblem::notFound('Lektion');
        }
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $lesson->module->course_id, 'video');
        $orderedLessons = $course->videoModules()->with('lessons')->orderBy('sort_order')->get()
            ->flatMap(fn (VideoModule $module) => $module->lessons)->values();
        if (! $this->access->allowsLimitedItem($tenant, $user, $course, 'video', $orderedLessons, (string) $lesson->id)) {
            throw ApiProblem::notFound('Lektion');
        }

        return $lesson;
    }

    private function progressFor(Request $request, Collection $lessons): Collection
    {
        return VideoProgress::where('tenant_id', $this->tenantContext->id())->where('user_id', $request->user()->id)
            ->whereIn('video_lesson_id', $lessons->pluck('id'))->get()->keyBy('video_lesson_id');
    }

    private function lesson(VideoLesson $lesson, ?VideoProgress $progress, bool $withSteps): array
    {
        $data = [
            'id' => $lesson->id,
            'module_id' => $lesson->video_module_id,
            'title' => $lesson->title,
            'description' => null,
            'duration_seconds' => (int) ($lesson->duration_seconds ?? 0),
            'media' => $lesson->isPlaceholder() || ! $lesson->video_url
                ? Present::placeholder('Video folgt')
                : ['kind' => 'video', 'available' => true, 'label' => $lesson->title, 'url' => Present::url($lesson->video_url),
                    'mime_type' => str_ends_with(strtolower((string) parse_url($lesson->video_url, PHP_URL_PATH)), '.mp4') ? 'video/mp4' : null,
                    'duration_seconds' => $lesson->duration_seconds, 'placeholder_reason' => null],
            'completed' => (bool) ($progress?->completed ?? false),
            'position_seconds' => (int) ($progress?->last_position_seconds ?? 0),
            'step_count' => $lesson->relationLoaded('steps') ? $lesson->steps->count() : null,
        ];
        if ($withSteps) {
            $data['steps'] = $lesson->steps->values()->map(fn (VideoLessonStep $s, int $i) => [
                'id' => $s->id,
                'order' => $i + 1,
                'title' => $s->title,
                'description' => null,
                'media' => [$s->image_path ? Present::image(Present::publicFile($s->image_path), $s->title) : Present::placeholder('Bild folgt')],
            ]);
        }

        return $data;
    }
}
