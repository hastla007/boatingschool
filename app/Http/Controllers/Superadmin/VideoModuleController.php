<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use App\Support\ComboVideoGroups;
use App\Support\SortOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Videokurs eines Kurses aufbauen: Kapitel und Lektionen anlegen, löschen
 * und umsortieren (Titel, Video und Quiz pflegt VideoLessonController).
 *
 * Die Video-Seeder legen den Videokurs eines Kurses nur an, solange er
 * noch gar keine Kapitel hat. Das letzte Kapitel eines Kurses lässt sich
 * deshalb nicht löschen, sonst käme beim nächsten db:seed der komplette
 * Ausgangsstand zurück.
 */
class VideoModuleController extends Controller
{
    public function store(Request $request, CourseDefinition $course): RedirectResponse
    {
        $validated = $request->validate(['title' => ['required', 'string', 'max:200']]);
        // Kombikurse spiegeln ihre Kapitel aus den Teilkursen (ComboVideoCourseSeeder);
        // ein nur hier angelegtes Kapitel hätte keine Kachel und wäre für Lernende nicht erreichbar.
        if (isset(ComboVideoGroups::GROUPS[$course->code])) {
            return redirect()->to(route('superadmin.courses.show', $course).'#videokurs')
                ->withErrors(['video_module' => 'In Kombikursen werden die Kapitel aus den Teilkursen übernommen. Bitte das Kapitel im Teilkurs anlegen.']);
        }
        $module = VideoModule::create([
            'course_id' => $course->id,
            'title' => $validated['title'],
            'sort_order' => (int) $course->videoModules()->max('sort_order') + 1,
        ]);
        AuditLog::record(null, $request->user()->id, 'superadmin.video_module.create', 'video_module', $module->id, null, ['title' => $module->title]);

        return redirect()->to(route('superadmin.courses.show', $course).'#videokurs')->with('status', 'Kapitel angelegt.');
    }

    public function update(Request $request, VideoModule $module): RedirectResponse
    {
        $validated = $request->validate(['title' => ['required', 'string', 'max:200']]);
        $back = route('superadmin.courses.show', $module->course_id).'#videokurs';
        if ($validated['title'] !== $module->title && ComboVideoGroups::isProtectedChapter($module->title)) {
            return redirect()->to($back)->withErrors(['video_module' => "Das Kapitel „{$module->title}“ ist mit einer eigenen Kachel bzw. mit Kombikursen verknüpft und kann nicht umbenannt werden."]);
        }
        $before = $module->title;
        $module->update($validated);
        AuditLog::record(null, $request->user()->id, 'superadmin.video_module.update', 'video_module', $module->id, ['title' => $before], ['title' => $module->title]);

        return redirect()->to($back)->with('status', 'Kapitel umbenannt.');
    }

    public function destroy(Request $request, VideoModule $module): RedirectResponse
    {
        $back = route('superadmin.courses.show', $module->course_id).'#videokurs';
        if ($module->lessons()->exists()) {
            return redirect()->to($back)->withErrors(['video_module' => 'Das Kapitel enthält noch Lektionen. Bitte zuerst die Lektionen löschen.']);
        }
        if (VideoModule::where('course_id', $module->course_id)->count() === 1) {
            return redirect()->to($back)->withErrors(['video_module' => 'Das letzte Kapitel eines Videokurses kann nicht gelöscht werden.']);
        }
        $module->delete();
        AuditLog::record(null, $request->user()->id, 'superadmin.video_module.delete', 'video_module', $module->id, ['title' => $module->title], null);

        return redirect()->to($back)->with('status', 'Kapitel gelöscht.');
    }

    public function move(Request $request, VideoModule $module): RedirectResponse
    {
        $request->validate(['direction' => ['required', 'in:up,down']]);
        SortOrder::move(VideoModule::where('course_id', $module->course_id)->orderBy('sort_order')->orderBy('title')->get(), $module, $request->input('direction'));

        return redirect()->to(route('superadmin.courses.show', $module->course_id).'#videokurs');
    }

    public function storeLesson(Request $request, VideoModule $module): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'video_url' => ['nullable', 'string', 'max:2048', 'url:https'],
        ], ['video_url.url' => 'Bitte eine vollständige https-Adresse angeben.']);

        $lesson = VideoLesson::create([
            'video_module_id' => $module->id,
            'title' => $validated['title'],
            // Ohne Video zunächst als Platzhalter (wird in der Übersicht markiert).
            'video_url' => $validated['video_url'] ?? VideoLesson::PLACEHOLDER_URL,
            'sort_order' => (int) $module->lessons()->max('sort_order') + 1,
        ]);
        AuditLog::record(null, $request->user()->id, 'superadmin.video_lesson.create', 'video_lesson', $lesson->id, null, ['title' => $lesson->title]);

        return redirect()->route('superadmin.video-lessons.show', $lesson)->with('status', 'Lektion angelegt. Video, Dauer und Quizfragen hier pflegen.');
    }

    public function destroyLesson(Request $request, VideoLesson $lesson): RedirectResponse
    {
        $courseId = $lesson->module->course_id;
        // Lernstand und Quiz-Zuordnung der Lektion gehen per ON DELETE CASCADE mit.
        $lesson->delete();
        AuditLog::record(null, $request->user()->id, 'superadmin.video_lesson.delete', 'video_lesson', $lesson->id, ['title' => $lesson->title], null);

        return redirect()->to(route('superadmin.courses.show', $courseId).'#videokurs')->with('status', "Lektion „{$lesson->title}“ gelöscht.");
    }

    public function moveLesson(Request $request, VideoLesson $lesson): RedirectResponse
    {
        $request->validate(['direction' => ['required', 'in:up,down']]);
        SortOrder::move(VideoLesson::where('video_module_id', $lesson->video_module_id)->orderBy('sort_order')->orderBy('title')->get(), $lesson, $request->input('direction'));

        return redirect()->to(route('superadmin.courses.show', $lesson->module->course_id).'#videokurs');
    }
}
