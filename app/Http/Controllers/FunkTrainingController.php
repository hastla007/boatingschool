<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\RadioTrainingProgress;
use App\Services\EntitlementService;
use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Funk-Training für SRC und UBI: Praxis-Prüfungssimulation (Aufgaben mit
 * Rang, DSC-Controller bzw. Verkehrskreis, Funkgerät und Meldung, regelbasiert
 * ausgewertet, siehe FunkPraxis) und mündliche Prüfung (Seefunktexte und
 * Vokabeln). Inhalte sind globaler Lernstoff aus database/data, der
 * Fortschritt liegt mandantengebunden in radio_training_progress.
 */
class FunkTrainingController extends Controller
{
    public function praxisIndex(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $catalog = $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $request->query('teil'));
        $tasks = FunkPraxis::tasks($catalog);
        $completed = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_PRAXIS, array_column($tasks, 'key'))
            ->filter(fn ($p) => $p->completed)->keys()->all();

        return view('funk.praxis.index', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'catalog' => $catalog,
            'tasks' => $tasks,
            'completed' => $completed,
            'percent' => $tasks ? (int) round(count($completed) / count($tasks) * 100) : 0,
        ]);
    }

    public function praxisShow(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $catalog, int $number): View
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);
        $tasks = FunkPraxis::tasks($catalog);
        abort_unless(isset($tasks[$number]), 404);
        $task = $tasks[$number];

        $progress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_PRAXIS, [$task['key']])->get($task['key']);
        $answers = $progress?->answers ?? [];
        $evaluation = $request->boolean('auswertung') && $answers ? FunkPraxis::evaluate($task, $answers) : null;

        return view('funk.praxis.show', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'catalog' => $catalog,
            'task' => $task,
            'total' => count($tasks),
            'previous' => isset($tasks[$number - 1]) ? $number - 1 : null,
            'next' => isset($tasks[$number + 1]) ? $number + 1 : null,
            'answers' => $answers,
            'completed' => (bool) $progress?->completed,
            'evaluation' => $evaluation,
        ]);
    }

    public function praxisEvaluate(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $catalog, int $number): Response
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);
        $task = FunkPraxis::tasks($catalog)[$number] ?? abort(404);

        $fieldNames = array_column($task['fields'], 'name');
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'string', 'max:5000'],
        ]);
        $answers = array_intersect_key($validated['answers'], array_flip($fieldNames));

        if (trim(implode('', $answers)) === '') {
            return back()->withErrors(['answers' => 'Trag zuerst etwas ein, dann kannst du auswerten.']);
        }

        $this->store($tenantContext, $request, RadioTrainingProgress::TYPE_PRAXIS, $task['key'], ['answers' => $answers]);

        return redirect()->route('funk.praxis.show', ['course' => $course, 'catalog' => $catalog, 'number' => $number, 'auswertung' => 1]);
    }

    /** Als erledigt markieren bzw. die Markierung wieder entfernen (Praxisaufgabe oder Seefunktext). */
    public function toggleCompleted(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $type, string $catalog, int $number): Response
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);

        // praxis: Praxisaufgabe; text: SRC-Seefunktext; aufnahme/abgabe: UBI-Funktext.
        $items = match ($type) {
            'praxis' => FunkPraxis::tasks($catalog),
            'text' => FunkTraining::texts($catalog),
            'aufnahme', 'abgabe' => $catalog === 'UBI' ? FunkTraining::ubiTexts($type) : [],
            default => [],
        };
        abort_unless(isset($items[$number]), 404);
        $key = $items[$number]['key'];
        $itemType = $type === 'praxis' ? RadioTrainingProgress::TYPE_PRAXIS : RadioTrainingProgress::TYPE_TEXT;

        $current = $this->progress($tenantContext, $request, $itemType, [$key])->get($key);
        $this->store($tenantContext, $request, $itemType, $key, ['completed' => ! $current?->completed]);

        return back();
    }

    public function oralIndex(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $catalog = $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $request->query('teil'));

        if ($catalog === 'UBI') {
            return $this->ubiOralIndex($request, $tenantContext, $course);
        }

        $modul = $request->query('modul') === 'vokabeln' ? 'vokabeln' : 'texte';
        $ansicht = $request->query('ansicht') === 'uebersicht' ? 'uebersicht' : 'quiz';

        $texts = FunkTraining::texts($catalog);
        $vocabulary = FunkTraining::vocabulary($catalog);
        $vocabKeys = collect($vocabulary)->flatten(1)->pluck('key')->all();

        $textProgress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, array_column($texts, 'key'));
        $vocabProgress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_VOCAB, $vocabKeys);

        return view('funk.oral.index', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'catalog' => $catalog,
            'modul' => $modul,
            'ansicht' => $ansicht,
            'texts' => $texts,
            'textsCompleted' => $textProgress->filter(fn ($p) => $p->completed)->keys()->all(),
            'vocabulary' => $vocabulary,
            'vocabLearned' => $vocabProgress->filter(fn ($p) => $p->completed)->keys()->all(),
            'vocabStreaks' => $vocabProgress->map(fn ($p) => $p->streak)->all(),
            'vocabTotal' => count($vocabKeys),
            'streakToLearn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN,
        ]);
    }

    public function textShow(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $catalog, int $number): View
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);
        $texts = FunkTraining::texts($catalog);
        abort_unless(isset($texts[$number]), 404);
        $text = $texts[$number];
        $direction = $request->query('richtung') === 'en-de' ? 'en-de' : 'de-en';
        [$from, $to] = $direction === 'de-en' ? ['de', 'en'] : ['en', 'de'];

        $progress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, [$text['key']])->get($text['key']);
        $answers = $progress?->answers[$direction] ?? [];
        $evaluation = $request->boolean('auswertung') && $answers ? [
            'transcript' => FunkTraining::wordMatch($text[$from], $answers['transcript'] ?? ''),
            'translation' => FunkTraining::wordMatch($text[$to], $answers['translation'] ?? ''),
        ] : null;

        return view('funk.oral.text', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'catalog' => $catalog,
            'text' => $text,
            'total' => count($texts),
            'direction' => $direction,
            'from' => $from,
            'to' => $to,
            'previous' => isset($texts[$number - 1]) ? $number - 1 : null,
            'next' => isset($texts[$number + 1]) ? $number + 1 : null,
            'answers' => $answers,
            'completed' => (bool) $progress?->completed,
            'evaluation' => $evaluation,
        ]);
    }

    public function textEvaluate(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $catalog, int $number): Response
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);
        $text = FunkTraining::texts($catalog)[$number] ?? abort(404);

        $validated = $request->validate([
            'richtung' => ['required', Rule::in(['de-en', 'en-de'])],
            'transcript' => ['nullable', 'string', 'max:5000'],
            'translation' => ['nullable', 'string', 'max:5000'],
        ]);

        if (trim(($validated['transcript'] ?? '').($validated['translation'] ?? '')) === '') {
            return back()->withErrors(['transcript' => 'Schreib zuerst mit oder übersetze, dann kannst du auswerten.']);
        }

        $current = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, [$text['key']])->get($text['key']);
        $answers = $current?->answers ?? [];
        $answers[$validated['richtung']] = ['transcript' => $validated['transcript'] ?? '', 'translation' => $validated['translation'] ?? ''];
        $this->store($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, $text['key'], ['answers' => $answers]);

        return redirect()->route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $number, 'richtung' => $validated['richtung'], 'auswertung' => 1]);
    }

    private function ubiOralIndex(Request $request, TenantContext $tenantContext, CourseDefinition $course): View
    {
        $modul = in_array($request->query('modul'), ['abgabe', 'buchstabiertafel'], true) ? $request->query('modul') : 'aufnahme';
        $texts = $modul === 'buchstabiertafel' ? [] : FunkTraining::ubiTexts($modul);
        $alphabet = FunkTraining::vocabulary('UBI');
        $alphabetProgress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_VOCAB, collect($alphabet)->flatten(1)->pluck('key')->all());

        return view('funk.oral.ubi-index', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'catalog' => 'UBI',
            'modul' => $modul,
            'ansicht' => $request->query('ansicht') === 'uebersicht' ? 'uebersicht' : 'quiz',
            'texts' => $texts,
            'completed' => $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, array_column($texts, 'key'))
                ->filter(fn ($p) => $p->completed)->keys()->all(),
            'vocabulary' => $alphabet,
            'vocabLearned' => $alphabetProgress->filter(fn ($p) => $p->completed)->keys()->all(),
            'vocabStreaks' => $alphabetProgress->map(fn ($p) => $p->streak)->all(),
            'vocabTotal' => count(FunkTraining::ALPHABET),
            'streakToLearn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN,
        ]);
    }

    /** UBI-Aufnahme (mitschreiben) bzw. -Abgabe (vorlesen und buchstabieren). */
    public function ubiTextShow(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $modul, int $number): View
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, 'UBI', strict: true);
        $texts = FunkTraining::ubiTexts($modul);
        abort_unless(isset($texts[$number]), 404);
        $text = $texts[$number];

        $progress = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, [$text['key']])->get($text['key']);
        $transcript = $progress?->answers['transcript'] ?? '';

        return view('funk.oral.ubi-text', [
            'course' => $course,
            'catalogs' => FunkTraining::catalogsFor($course),
            'modul' => $modul,
            'text' => $text,
            'total' => count($texts),
            'previous' => isset($texts[$number - 1]) ? $number - 1 : null,
            'next' => isset($texts[$number + 1]) ? $number + 1 : null,
            'transcript' => $transcript,
            'completed' => (bool) $progress?->completed,
            'evaluation' => $modul === 'aufnahme' && $request->boolean('auswertung') && $transcript !== '' ? FunkTraining::wordMatch($text['text'], $transcript) : null,
        ]);
    }

    public function ubiTextEvaluate(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, int $number): Response
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, 'UBI', strict: true);
        $text = FunkTraining::ubiTexts('aufnahme')[$number] ?? abort(404);
        $validated = $request->validate(['transcript' => ['required', 'string', 'max:5000']]);

        $this->store($tenantContext, $request, RadioTrainingProgress::TYPE_TEXT, $text['key'], ['answers' => ['transcript' => $validated['transcript']]]);

        return redirect()->route('funk.oral.ubi', ['course' => $course, 'modul' => 'aufnahme', 'number' => $number, 'auswertung' => 1]);
    }

    /** Vokabel-Quiz: "gewusst" erhöht die Serie, "nicht gewusst" setzt sie zurück; nach 3x in Folge gilt sie als gelernt. */
    public function vocabAnswer(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $catalog): JsonResponse
    {
        $this->authorizeCatalog($request, $tenantContext, $entitlements, $course, $catalog, strict: true);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'known' => ['required', 'boolean'],
        ]);

        $keys = collect(FunkTraining::vocabulary($catalog))->flatten(1)->pluck('key');
        abort_unless($keys->contains($validated['key']), 404);

        $current = $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_VOCAB, [$validated['key']])->get($validated['key']);
        $streak = $validated['known'] ? ($current?->streak ?? 0) + 1 : 0;
        $learned = $current?->completed || $streak >= RadioTrainingProgress::VOCAB_STREAK_TO_LEARN;
        $this->store($tenantContext, $request, RadioTrainingProgress::TYPE_VOCAB, $validated['key'], ['streak' => $streak, 'completed' => $learned]);

        return response()->json([
            'streak' => $streak,
            'learned' => $learned,
            'learnedCount' => $this->progress($tenantContext, $request, RadioTrainingProgress::TYPE_VOCAB, $keys->all())->filter(fn ($p) => $p->completed)->count(),
        ]);
    }

    private function authorizeCatalog(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, ?string $catalog, bool $strict = false): string
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);

        $selected = FunkTraining::selectedCatalog($course, $catalog);
        abort_if($selected === null || ($strict && $selected !== $catalog), 404);

        return $selected;
    }

    /** @return Collection<string, RadioTrainingProgress> */
    private function progress(TenantContext $tenantContext, Request $request, string $type, array $keys): Collection
    {
        return RadioTrainingProgress::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('item_type', $type)
            ->whereIn('item_key', $keys)
            ->get()
            ->keyBy('item_key');
    }

    private function store(TenantContext $tenantContext, Request $request, string $type, string $key, array $values): void
    {
        RadioTrainingProgress::updateOrCreate(
            ['tenant_id' => $tenantContext->id(), 'user_id' => $request->user()->id, 'item_type' => $type, 'item_key' => $key],
            $values + ['updated_at' => now()]
        );
    }
}
