<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fragen/Antworten-Editor für den Superadmin. Eine Änderung erzeugt --
 * konsistent mit dem Import-Workflow (ImportContentQuestions) -- immer eine
 * neue, unveränderliche Revision statt die bestehende zu überschreiben; die
 * bisherige veröffentlichte Revision wird dabei auf "deprecated" gesetzt,
 * damit publishedRevision() genau eine aktuelle Fassung liefert.
 */
class QuestionController extends Controller
{
    public function index(Module $module): View
    {
        $questions = $module->questions()->with(['revisions' => fn ($q) => $q->orderByDesc('revision_no')])->get();

        return view('superadmin.questions.index', ['module' => $module, 'questions' => $questions]);
    }

    public function create(Module $module): View
    {
        return view('superadmin.questions.create', ['module' => $module]);
    }

    public function store(Request $request, Module $module): Response
    {
        $validated = $this->validateQuestion($request);

        DB::transaction(function () use ($validated, $module) {
            $question = ContentQuestion::create([
                'content_id' => 'manual-'.str()->uuid(),
                'official_number' => $validated['official_number'] ?? null,
                'content_role' => null,
                'language' => 'de-DE',
                'active' => true,
            ]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => $validated['question_text'],
                'feedback_correct' => $validated['feedback_correct'] ?? null,
                'feedback_incorrect' => $validated['feedback_incorrect'] ?? null,
                'topic' => $validated['topic'] ?? null,
                'subtopic' => $validated['subtopic'] ?? null,
                'smartmodus_kategorie' => $validated['smartmodus_kategorie'] ?? null,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            $this->storeAnswers($revision, $validated);

            $module->questions()->syncWithoutDetaching([$question->id => ['required' => true]]);
        });

        return redirect()->route('superadmin.questions.index', $module)->with('status', 'Frage angelegt.');
    }

    public function edit(ContentQuestion $question): View
    {
        $revision = $question->publishedRevision() ?? $question->revisions()->orderByDesc('revision_no')->first();
        $revision?->loadMissing('answers');

        return view('superadmin.questions.edit', ['question' => $question, 'revision' => $revision]);
    }

    public function update(Request $request, ContentQuestion $question): Response
    {
        $validated = $this->validateQuestion($request);

        DB::transaction(function () use ($validated, $question) {
            $latest = $question->revisions()->orderByDesc('revision_no')->first();

            $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => $latest ? $latest->revision_no + 1 : 1,
                'question_type' => 'single_choice',
                'question_text' => $validated['question_text'],
                'feedback_correct' => $validated['feedback_correct'] ?? null,
                'feedback_incorrect' => $validated['feedback_incorrect'] ?? null,
                'topic' => $validated['topic'] ?? null,
                'subtopic' => $validated['subtopic'] ?? null,
                'smartmodus_kategorie' => $validated['smartmodus_kategorie'] ?? null,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            $this->storeAnswers($revision, $validated);
        });

        return redirect()->route('superadmin.questions.edit', $question)->with('status', 'Neue Fassung gespeichert.');
    }

    public function toggleActive(ContentQuestion $question): Response
    {
        $question->update(['active' => ! $question->active]);

        return back()->with('status', $question->active ? 'Frage aktiviert.' : 'Frage deaktiviert.');
    }

    /** @return array<string, mixed> */
    private function validateQuestion(Request $request): array
    {
        return $request->validate([
            'question_text' => ['required', 'string'],
            'feedback_correct' => ['nullable', 'string'],
            'feedback_incorrect' => ['nullable', 'string'],
            'topic' => ['nullable', 'string', 'max:255'],
            'subtopic' => ['nullable', 'string', 'max:255'],
            'smartmodus_kategorie' => ['nullable', 'string', 'max:255'],
            'official_number' => ['nullable', 'string', 'max:60'],
            'answers' => ['required', 'array'],
            'answers.A' => ['required', 'string'],
            'answers.B' => ['required', 'string'],
            'answers.C' => ['required', 'string'],
            'answers.D' => ['required', 'string'],
            'correct' => ['required', 'in:A,B,C,D'],
        ]);
    }

    private function storeAnswers(ContentQuestionRevision $revision, array $validated): void
    {
        foreach (['A', 'B', 'C', 'D'] as $index => $key) {
            ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => $key,
                'answer_text' => $validated['answers'][$key],
                'is_correct' => $key === $validated['correct'],
                'sort_order' => $index + 1,
            ]);
        }
    }
}
