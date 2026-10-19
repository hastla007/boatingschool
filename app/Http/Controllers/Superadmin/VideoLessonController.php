<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\VideoLesson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verwaltet die "Zu den Fragen"-Quizfragen einer Videolektion (siehe
 * video_lesson_question-Pivot). Fragen werden nicht neu angelegt, sondern
 * aus der bestehenden zentralen Fragenbasis per Volltextsuche ausgewählt --
 * konsistent damit, dass Module/Kurse/Praxistrainer dieselbe Fragenbasis
 * wiederverwenden statt eigene Kopien zu pflegen.
 */
class VideoLessonController extends Controller
{
    public function show(Request $request, VideoLesson $lesson): View
    {
        $lesson->load(['module.course', 'questions.revisions']);

        $query = trim((string) $request->query('q', ''));
        $results = collect();

        if ($query !== '') {
            $attachedIds = $lesson->questions->pluck('id');
            $results = ContentQuestionRevision::where('editorial_status', 'published')
                ->where('question_text', 'ilike', '%'.$query.'%')
                ->whereNotIn('question_id', $attachedIds)
                ->orderBy('question_text')
                ->limit(20)
                ->get();
        }

        return view('superadmin.video-lessons.show', [
            'lesson' => $lesson,
            'query' => $query,
            'results' => $results,
        ]);
    }

    public function attachQuestion(Request $request, VideoLesson $lesson): Response
    {
        $validated = $request->validate([
            'question_id' => ['required', 'uuid', 'exists:content_question,id'],
        ]);

        $nextOrder = (int) $lesson->questions()->max('video_lesson_question.sort_order') + 1;

        $lesson->questions()->syncWithoutDetaching([
            $validated['question_id'] => ['sort_order' => $nextOrder],
        ]);

        return back()->with('status', 'Frage hinzugefügt.');
    }

    public function detachQuestion(VideoLesson $lesson, ContentQuestion $question): Response
    {
        $lesson->questions()->detach($question->id);

        return back()->with('status', 'Frage entfernt.');
    }
}
