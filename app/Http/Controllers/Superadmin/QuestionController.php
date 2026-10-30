<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fragen/Antworten-Editor für den Superadmin. Eine Änderung erzeugt --
 * konsistent mit dem Import-Workflow (ImportContentQuestions) -- immer eine
 * neue, unveränderliche Revision statt die bestehende zu überschreiben; die
 * bisherige veröffentlichte Revision wird dabei auf "deprecated" gesetzt,
 * damit publishedRevision() genau eine aktuelle Fassung liefert.
 *
 * Bug-Review (Hoch): store()/update() setzten question_type bisher immer
 * hart auf 'single_choice' und validateQuestion() verlangte zwingend genau
 * die Antworten A-D plus ein "correct" -- unabhängig vom TATSÄCHLICHEN Typ
 * der bearbeiteten Frage. Jede Bearbeitung einer multi_choice- oder
 * open_text-Frage (siehe FknSknContentSeeder, 151 FKN/SKN-Fragen) erzeugte
 * dadurch eine neue Revision, die deren echte Antwortstruktur (mehrere
 * richtige Antworten bzw. eine Musterantwort ohne Auswahlmöglichkeiten)
 * durch genau 4 Single-Choice-Antworten ersetzte. question_type kommt jetzt
 * aus dem Formular (bei einer bestehenden Frage unveränderlich, siehe View)
 * und steuert typabhängig sowohl Validierung als auch Antwort-Speicherung.
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
        $validated = $this->validateQuestion($request, null);

        DB::transaction(function () use ($validated, $module) {
            $question = ContentQuestion::create([
                'content_id' => 'manual-'.str()->uuid(),
                'official_number' => $validated['official_number'] ?? null,
                'content_role' => null,
                'language' => 'de-DE',
                'active' => true,
            ]);

            $revision = ContentQuestionRevision::create($this->revisionAttributes($question, 1, $validated));

            $this->storeAnswers($revision, $validated);

            $module->questions()->syncWithoutDetaching([$question->id => ['required' => true]]);
        });

        return redirect()->route('superadmin.questions.index', $module)->with('status', 'Frage angelegt.');
    }

    public function edit(ContentQuestion $question): View
    {
        $revision = $question->publishedRevision() ?? $question->revisions()->orderByDesc('revision_no')->first();
        $revision?->loadMissing('answers');

        // Bug-Review F8: wenn eine neuere "review"-Revision (siehe
        // ImportContentQuestions) existiert, bearbeitet das Formular oben
        // trotzdem weiterhin die aktuell veröffentlichte Fassung -- die
        // Vorschau hier zeigt den ausstehenden Entwurf separat an, damit er
        // sichtbar bleibt und veröffentlicht werden kann, statt beim
        // nächsten Speichern unbemerkt verwaist zu bleiben.
        $pendingReview = $question->pendingReviewRevision();
        $pendingReview?->loadMissing('answers');

        $latest = $question->revisions()->orderByDesc('revision_no')->first();

        return view('superadmin.questions.edit', [
            'question' => $question,
            'revision' => $revision,
            'pendingReview' => $pendingReview,
            'nextRevisionNo' => ($latest?->revision_no ?? 0) + 1,
        ]);
    }

    public function update(Request $request, ContentQuestion $question): Response
    {
        $latest = $question->revisions()->orderByDesc('revision_no')->first();
        // Bug-Review T3: die vom Editor-Formular bearbeitete Fassung ist
        // (siehe edit() oben) die VERÖFFENTLICHTE Revision, nicht
        // notwendigerweise $latest -- ImportContentQuestions (Bug-Review
        // F8) kann eine neuere "review"-Revision mit höherer revision_no
        // anlegen, während der Superadmin noch die ältere, veröffentlichte
        // Fassung im Formular offen hat. $latest bleibt bewusst nur für die
        // nächste revision_no maßgeblich (verhindert eine Kollision mit
        // einer wartenden Review-Revision); für den question_type-Abgleich
        // und die Medien-Übernahme unten zählt dagegen, was im Formular
        // tatsächlich angezeigt und bearbeitet wurde.
        $editing = $question->publishedRevision() ?? $latest;
        $validated = $this->validateQuestion($request, $editing?->question_type);

        DB::transaction(function () use ($validated, $question, $latest, $editing) {
            // Bug-Review (Fragen-Editor verliert Metadaten): official_number
            // liegt auf content_question, nicht auf der Revision -- bisher
            // wurde es hier nie aktualisiert (nur store() setzte es, einmalig
            // bei der Anlage), und das Formular zeigte das Feld bei einer
            // bestehenden Frage gar nicht erst an.
            $question->update(['official_number' => $validated['official_number'] ?? null]);

            $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);

            $revision = ContentQuestionRevision::create($this->revisionAttributes($question, $latest ? $latest->revision_no + 1 : 1, $validated));

            $this->storeAnswers($revision, $validated);

            // Bug-Review B13 (Folgefehler T3): eine neue Revision begann
            // bisher immer ohne Medien -- ein Bild verschwand dadurch von
            // der Frage, sobald sie das nächste Mal bearbeitet wurde, auch
            // ohne dass sich am Bild selbst etwas geändert hat. Bestehende
            // Medienzuordnungen werden deshalb übernommen -- von $editing
            // (der im Formular bearbeiteten, veröffentlichten Fassung),
            // nicht von $latest: eine wartende Review-Revision kann andere
            // oder gar keine Medien tragen, deren Übernahme hier das Bild
            // der tatsächlich bearbeiteten Fassung unbemerkt ersetzt hätte.
            if ($editing) {
                foreach ($editing->media as $media) {
                    $revision->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
                }
            }

            $revision->resetProgressIfContentChangedFrom($editing);
        });

        return redirect()->route('superadmin.questions.edit', $question)->with('status', 'Neue Fassung gespeichert.');
    }

    public function toggleActive(ContentQuestion $question): Response
    {
        $question->update(['active' => ! $question->active]);

        return back()->with('status', $question->active ? 'Frage aktiviert.' : 'Frage deaktiviert.');
    }

    /**
     * Bug-Review F8: eine von ImportContentQuestions bei einem geänderten
     * Reimport angelegte Revision im Status "review" erreichte Lerner
     * bisher nie -- es gab keinen Weg, sie zu veröffentlichen.
     */
    public function publishRevision(ContentQuestionRevision $revision): Response
    {
        abort_unless($revision->editorial_status === 'review', 404);

        // Bug-Review (Veröffentlichen einer Import-Revision verliert
        // Feedback/Bilder): ImportContentQuestions prüft beim Anlegen einer
        // neuen "review"-Revision nur die insgesamt letzte Revision der
        // Frage, nicht gezielt nach einer dort schon wartenden, noch nicht
        // veröffentlichten "review"-Revision -- zwei Reimports in Folge
        // können deshalb zwei "review"-Revisionen stapeln, ohne die ältere
        // zu verwerfen. Ein noch offener Link auf die ältere davon durfte
        // sie bisher trotzdem veröffentlichen und damit die inzwischen
        // neuere "review"-Revision verwaist (nie mehr erreichbar) zurück-
        // lassen.
        //
        // Bug-Review (AA9: Wartende Import-Änderung verschwindet): die
        // Prüfung verglich bisher gegen die höchste revision_no ÜBER ALLE
        // Revisionen -- speichert der Superadmin über den Editor, während
        // diese Review-Revision noch wartet (siehe QuestionController::
        // update(), T3), entsteht dadurch eine neue, bereits veröffentlichte
        // Revision mit einer noch höheren revision_no. Die wartende Review-
        // Revision galt damit fälschlich ebenfalls als "veraltet" und ließ
        // sich nie mehr veröffentlichen -- der Hinweistext ("Seite neu
        // laden") half nicht, da sich daran auch nach einem Neuladen nichts
        // ändert. Maßgeblich ist deshalb nur, ob eine NEUERE Review-Revision
        // (aus einem zweiten Reimport) existiert, nicht ein inzwischen vom
        // Editor unabhängig veröffentlichter Stand.
        $isLatestReview = $revision->revision_no === $revision->question->revisions()
            ->where('editorial_status', 'review')
            ->max('revision_no');
        abort_unless($isLatestReview, 409, 'Für diese Frage liegt inzwischen eine neuere Fassung vor -- bitte die Seite neu laden.');

        $revision->publish();

        return back()->with('status', 'Fassung veröffentlicht.');
    }

    /**
     * Bug-Review (AA9-Folgefehler: Freigabe einer älteren Import-Fassung
     * verwirft eine neuere Editor-Fassung): wartet eine Import-Revision,
     * während die Frage im Editor schon neu gespeichert wurde, ersetzt ihre
     * Freigabe die neuere Editor-Fassung. Die Bearbeitungsseite warnt davor;
     * ist die Import-Änderung überholt, lässt sie sich hier verwerfen.
     */
    public function discardRevision(ContentQuestionRevision $revision): Response
    {
        abort_unless($revision->editorial_status === 'review', 404);

        $revision->update(['editorial_status' => 'deprecated']);

        return back()->with('status', 'Wartende Fassung verworfen.');
    }

    /**
     * $existingType ist der Fragetyp der bisherigen Revision (null beim
     * Anlegen) -- der eingereichte question_type muss bei einer
     * BESTEHENDEN Frage exakt diesem entsprechen, da die View ihn nur als
     * verstecktes Feld mitsendet und ein nachträglicher Typwechsel die
     * Antwortstruktur der Frage grundlegend ändern würde (siehe
     * Klassen-Docblock).
     *
     * @return array<string, mixed>
     */
    private function validateQuestion(Request $request, ?string $existingType): array
    {
        $typeRules = ['required', 'in:single_choice,multi_choice,open_text'];

        if ($existingType) {
            $typeRules[] = "in:{$existingType}";
        }

        $base = $request->validate([
            'question_type' => $typeRules,
            'question_text' => ['required', 'string'],
            'feedback_correct' => ['nullable', 'string'],
            'feedback_incorrect' => ['nullable', 'string'],
            'topic' => ['nullable', 'string', 'max:255'],
            'subtopic' => ['nullable', 'string', 'max:255'],
            'smartmodus_kategorie' => ['nullable', 'string', 'max:255'],
            'official_number' => ['nullable', 'string', 'max:60'],
            // Bug-Review (Fragen-Editor verliert Metadaten): diese Felder
            // bestückt der Import (ImportContentQuestions/FknSknContentSeeder)
            // bereits -- das Editor-Formular kannte sie bisher gar nicht,
            // jede Bearbeitung nullte sie dadurch auf der neuen Revision.
            'competency' => ['nullable', 'string', 'max:255'],
            'image_required' => ['nullable', 'boolean'],
            'source_catalog' => ['nullable', 'string', 'max:120'],
            'source_version' => ['nullable', 'string', 'max:80'],
            'source_page' => ['nullable', 'string', 'max:40'],
            'source_question_id' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        // $request->boolean() statt des rohen validierten Werts: eine nicht
        // angehakte Checkbox fehlt im Request komplett (liefert hier null
        // statt false), eine angehakte käme als String "1" an.
        $base['image_required'] = $request->boolean('image_required');

        return $base + match ($base['question_type']) {
            'single_choice' => $this->validateSingleChoice($request),
            'multi_choice' => $this->validateMultiChoice($request),
            'open_text' => $request->validate(['model_answer' => ['required', 'string']]),
        };
    }

    /** @return array<string, mixed> */
    private function revisionAttributes(ContentQuestion $question, int $revisionNo, array $validated): array
    {
        return [
            'question_id' => $question->id,
            'revision_no' => $revisionNo,
            'question_type' => $validated['question_type'],
            'question_text' => $validated['question_text'],
            'feedback_correct' => $validated['feedback_correct'] ?? null,
            'feedback_incorrect' => $validated['feedback_incorrect'] ?? null,
            'topic' => $validated['topic'] ?? null,
            'subtopic' => $validated['subtopic'] ?? null,
            'smartmodus_kategorie' => $validated['smartmodus_kategorie'] ?? null,
            'competency' => $validated['competency'] ?? null,
            'image_required' => $validated['image_required'] ?? false,
            'source_catalog' => $validated['source_catalog'] ?? null,
            'source_version' => $validated['source_version'] ?? null,
            'source_page' => $validated['source_page'] ?? null,
            'source_question_id' => $validated['source_question_id'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_to' => $validated['valid_to'] ?? null,
            'editorial_status' => 'published',
            'rights_status' => 'licensed',
            // Bug-Review (Seed überschreibt UBI-Korrekturen aus dem Editor):
            // markiert diese Revision als vom Fragen-Editor angelegt, damit
            // ImportContentQuestions beim Diff gegen die zuletzt IMPORTIERTE
            // Fassung vergleicht statt gegen eine fachlich bereits
            // korrigierte Editor-Revision (siehe Migration
            // add_edited_by_superadmin_to_content_question_revision).
            'edited_by_superadmin' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function validateSingleChoice(Request $request): array
    {
        return $request->validate([
            'answers' => ['required', 'array'],
            'answers.A' => ['required', 'string'],
            'answers.B' => ['required', 'string'],
            'answers.C' => ['required', 'string'],
            'answers.D' => ['required', 'string'],
            'correct' => ['required', 'in:A,B,C,D'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateMultiChoice(Request $request): array
    {
        $validated = $request->validate([
            'multi_answers' => ['required', 'array'],
            'multi_answers.*.text' => ['nullable', 'string'],
            'multi_correct' => ['nullable', 'array'],
            'multi_correct.*' => ['string'],
        ]);

        $nonEmpty = collect($validated['multi_answers'])->filter(fn ($a) => trim((string) ($a['text'] ?? '')) !== '');

        if ($nonEmpty->count() < 2) {
            throw ValidationException::withMessages(['multi_answers' => 'Mindestens zwei Antwortoptionen sind erforderlich.']);
        }

        $correctKeys = array_map('strval', $validated['multi_correct'] ?? []);

        if ($nonEmpty->keys()->map('strval')->intersect($correctKeys)->isEmpty()) {
            throw ValidationException::withMessages(['multi_correct' => 'Mindestens eine Antwort muss als richtig markiert sein.']);
        }

        return $validated;
    }

    private function storeAnswers(ContentQuestionRevision $revision, array $validated): void
    {
        match ($validated['question_type']) {
            'single_choice' => $this->storeSingleChoiceAnswers($revision, $validated),
            'multi_choice' => $this->storeMultiChoiceAnswers($revision, $validated),
            'open_text' => ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => 'MODEL',
                'answer_text' => $validated['model_answer'],
                'is_correct' => true,
                'sort_order' => 1,
            ]),
        };
    }

    private function storeSingleChoiceAnswers(ContentQuestionRevision $revision, array $validated): void
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

    private function storeMultiChoiceAnswers(ContentQuestionRevision $revision, array $validated): void
    {
        $correctKeys = array_map('strval', $validated['multi_correct'] ?? []);
        $position = 0;

        foreach ($validated['multi_answers'] as $index => $answer) {
            $text = trim((string) ($answer['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => chr(ord('A') + $position),
                'answer_text' => $text,
                'is_correct' => in_array((string) $index, $correctKeys, true),
                'sort_order' => $position + 1,
            ]);

            $position++;
        }
    }
}
