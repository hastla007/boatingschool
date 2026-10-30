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

        // Bug-Review (Array-Werte in der Adresszeile): ?q[]=x ergab "Array to string conversion" (HTTP 500).
        $query = is_string($request->query('q')) ? trim($request->query('q')) : '';
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
            'copies' => $lesson->copies()->with('module.course')->get(),
            'query' => $query,
            'results' => $results,
        ]);
    }

    /**
     * Titel, Video-URL und Dauer einer Lektion pflegen. Der Player
     * (video/show.blade.php) spielt die URL direkt per <video> ab -- nur
     * https, damit kein Mixed Content entsteht und die CSP (media-src
     * 'self' https:) greift.
     */
    public function update(Request $request, VideoLesson $lesson): Response
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'video_url' => ['required', 'string', 'max:2048', 'url:https'],
            'duration' => ['nullable', 'string', 'regex:/^\d{1,3}(:[0-5]\d)?$/'],
            'apply_to_copies' => ['nullable', 'boolean'],
        ], [
            'video_url.url' => 'Bitte eine vollständige https-Adresse angeben.',
            'duration.regex' => 'Dauer bitte als Minuten:Sekunden (z. B. 4:30) oder in Minuten angeben.',
        ]);

        $lesson->load('module');
        $copies = $request->boolean('apply_to_copies') ? $lesson->copies()->get() : collect();

        $lesson->update([
            'title' => $validated['title'],
            'video_url' => $validated['video_url'],
            'duration_seconds' => $this->durationSeconds($validated['duration'] ?? null),
        ]);

        // Kopien bekommen nur die Video-URL und Dauer -- der Titel bleibt
        // ihr eigener, sie wurden ja gerade über den (alten) Titel gefunden.
        foreach ($copies as $copy) {
            $copy->update([
                'video_url' => $lesson->video_url,
                'duration_seconds' => $lesson->duration_seconds,
            ]);
        }

        $status = $copies->isEmpty()
            ? 'Lektion gespeichert.'
            : 'Lektion gespeichert, Video auch in '.$copies->count().' '.($copies->count() === 1 ? 'Kopie' : 'Kopien').' übernommen.';

        return redirect()->route('superadmin.video-lessons.show', $lesson)->with('status', $status);
    }

    /** "4:30" → 270, "12" (Minuten) → 720, leer → null. */
    private function durationSeconds(?string $duration): ?int
    {
        if ($duration === null || $duration === '') {
            return null;
        }

        [$minutes, $seconds] = array_pad(explode(':', $duration), 2, '0');

        return (int) $minutes * 60 + (int) $seconds;
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
