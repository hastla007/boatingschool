<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\RadioTrainingProgress;
use App\Support\CanonicalUrl;
use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use App\Support\Geraetesimulation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Funk-Training (SRC/UBI) für die App: dieselben Inhalte und Prüfungen wie
 * im Web (FunkTraining, FunkPraxis, FunkTrainingController) und derselbe
 * Lernstand in radio_training_progress -- was in der App geübt wird, ist im
 * Web erledigt und umgekehrt.
 *
 * Texte: translation (Seefunktexte, Antworten je Übersetzungsrichtung),
 * dictation (UBI-Aufnahme, Antwort {transcript}) und spelling (UBI-Abgabe,
 * nur vorlesen, nicht auswertbar).
 */
class RadioController extends Controller
{
    private const TEXT_TYPES = ['aufnahme' => 'dictation', 'abgabe' => 'spelling'];

    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $catalogs = FunkTraining::catalogsFor($course);
        // Eine Abfrage für den ganzen Lernstand statt je Text/Kategorie.
        $all = RadioTrainingProgress::where('tenant_id', $this->tenantContext->id())->where('user_id', $request->user()->id)
            ->get()->groupBy('item_type')->map(fn (Collection $rows) => $rows->keyBy('item_key'));
        $progress = fn (string $type) => $all->get($type, collect());

        $texts = [];
        $vocabulary = [];
        $practice = [];
        foreach ($catalogs as $catalog) {
            foreach ($this->textGroups($catalog) as $type => $items) {
                $texts[] = ['catalog' => $catalog, 'type' => $type, 'items' => array_map(
                    fn (array $item) => $this->textSummary($item, $type, $progress(RadioTrainingProgress::TYPE_TEXT)->get($item['key'])),
                    array_values($items)
                )];
            }
            foreach (FunkTraining::vocabulary($catalog) as $category => $cards) {
                $vocabulary[] = [
                    'catalog' => $catalog,
                    'category' => $category,
                    'total' => count($cards),
                    'learned' => $progress(RadioTrainingProgress::TYPE_VOCAB)->only(array_column($cards, 'key'))->where('completed', true)->count(),
                ];
            }
            $practice[] = ['catalog' => $catalog, 'tasks' => $this->practiceRows($catalog, $progress(RadioTrainingProgress::TYPE_PRAXIS))];
        }

        $deviceAvailable = Geraetesimulation::availableFor($request->user(), $course);

        return response()->json(['data' => [
            'course_id' => $course->id,
            'catalogs' => $catalogs,
            'texts' => $texts,
            'vocabulary' => $vocabulary,
            'practice' => $practice,
            // Die Gerätesimulation gibt es nur im Browser.
            'device_simulator' => [
                'available' => $deviceAvailable,
                'web_url' => $deviceAvailable ? $this->webUrl(route('funk.device.index', $course)) : null,
                'open_in' => 'browser',
                'lessons' => $deviceAvailable ? count(Geraetesimulation::lessonsForBrowser(Geraetesimulation::catalogsFor($course))) : 0,
                'mastered' => $deviceAvailable ? $progress(RadioTrainingProgress::TYPE_DEVICE)->where('completed', true)->count() : 0,
            ],
        ], 'meta' => ['streak_to_learn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN]]);
    }

    /** Buchstabiertafel und Ziffern im Sprechfunk. */
    public function spelling(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $letters = [];
        foreach (FunkTraining::ALPHABET as $letter => [$word, $hint]) {
            $letters[] = ['letter' => $letter, 'word' => $word, 'hint' => $hint];
        }

        return response()->json(['data' => [
            'catalogs' => FunkTraining::catalogsFor($course),
            'letters' => $letters,
            'digits' => array_map(fn (int $digit, string $word) => ['digit' => (string) $digit, 'word' => $word], array_keys(FunkTraining::DIGITS), FunkTraining::DIGITS),
        ]]);
    }

    public function showText(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        ['item' => $item, 'catalog' => $catalog, 'type' => $type, 'siblings' => $siblings] = $this->textRecord($course, $textId);
        $direction = $request->query('direction', 'de-en');
        if (! in_array($direction, ['de-en', 'en-de'], true)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['direction' => ['Ungültige Übersetzungsrichtung.']]);
        }
        $saved = $this->progress($request, RadioTrainingProgress::TYPE_TEXT, [$item['key']])->get($item['key']);
        $answers = $saved?->answers ?? [];

        $textDe = $item['de'] ?? ($item['text'] ?? null);
        $textEn = $item['en'] ?? null;
        $lines = isset($item['lines']) ? array_map(fn (string $line) => ['text' => $line, 'spelled' => FunkTraining::spellMarkedWords($line)], $item['lines']) : null;
        // Übersetzung: die Lösung erst nach der ersten Auswertung in dieser Richtung.
        $hidden = $type === 'translation' && empty($answers[$direction]);
        if ($hidden && $direction === 'de-en') {
            $textEn = null;
        } elseif ($hidden) {
            $textDe = null;
        }

        $keys = array_column($siblings, 'key');
        $index = array_search($item['key'], $keys, true);

        return response()->json(['data' => [
            'id' => $item['key'],
            'course_id' => $course->id,
            'catalog' => $catalog,
            'type' => $type,
            'number' => (int) $item['number'],
            'direction' => $item['richtung'] ?? null,
            'translation_direction' => $type === 'translation' ? $direction : null,
            'solution_hidden' => $hidden,
            'evaluable' => $type !== 'spelling',
            'text_de' => $textDe,
            'text_en' => $textEn,
            'lines' => $lines,
            'answers' => (object) ($type === 'translation' ? ($answers[$direction] ?? []) : $answers),
            'done' => (bool) ($saved?->completed ?? false),
            'previous_id' => $keys[$index - 1] ?? null,
            'next_id' => $keys[$index + 1] ?? null,
        ]]);
    }

    /** Mitschrift/Übersetzung speichern und grob auswerten (wie FunkTrainingController::textEvaluate/ubiTextEvaluate). */
    public function evaluateText(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        ['item' => $item, 'type' => $type] = $this->textRecord($course, $textId);
        if ($type === 'spelling') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['text' => ['Abgabe-Texte werden nicht ausgewertet.']]);
        }
        $validated = $request->validate([
            'direction' => [$type === 'translation' ? 'required' : 'nullable', 'in:de-en,en-de'],
            'transcript' => ['nullable', 'string', 'max:5000'],
            'translation' => ['nullable', 'string', 'max:5000'],
        ]);
        $transcript = (string) ($validated['transcript'] ?? '');
        $translation = (string) ($validated['translation'] ?? '');
        if (trim($transcript.($type === 'translation' ? $translation : '')) === '') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['transcript' => ['Schreib zuerst mit oder übersetze, dann kannst du auswerten.']]);
        }
        // Ein bewusst leer gelassenes Feld wird nicht mit 0 % bewertet (wie im Web).
        $evaluate = fn (string $original, string $answer) => trim($answer) !== '' ? FunkTraining::wordMatch($original, $answer) : null;

        if ($type === 'translation') {
            $direction = $validated['direction'];
            [$from, $to] = $direction === 'de-en' ? ['de', 'en'] : ['en', 'de'];
            $answers = $this->progress($request, RadioTrainingProgress::TYPE_TEXT, [$item['key']])->get($item['key'])?->answers ?? [];
            $answers[$direction] = ['transcript' => $transcript, 'translation' => $translation];
            $this->store($request, RadioTrainingProgress::TYPE_TEXT, $item['key'], ['answers' => $answers]);

            return response()->json(['data' => [
                'text_id' => $item['key'], 'direction' => $direction,
                'transcript' => $evaluate($item[$from], $transcript),
                'translation' => $evaluate($item[$to], $translation),
                'solution' => ['from' => $item[$from], 'to' => $item[$to]],
                'answers' => $answers[$direction],
            ]]);
        }

        // UBI-Aufnahme: nur mitschreiben; gespeichert wie im Web als {transcript}.
        $this->store($request, RadioTrainingProgress::TYPE_TEXT, $item['key'], ['answers' => ['transcript' => $transcript]]);

        return response()->json(['data' => [
            'text_id' => $item['key'], 'direction' => null,
            'transcript' => $evaluate($item['text'], $transcript),
            'translation' => null,
            'solution' => ['from' => $item['text'], 'to' => null],
            'answers' => ['transcript' => $transcript],
        ]]);
    }

    public function setTextDone(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        ['item' => $item] = $this->textRecord($course, $textId);
        $validated = $request->validate(['done' => ['required', 'boolean']]);
        $this->store($request, RadioTrainingProgress::TYPE_TEXT, $item['key'], ['completed' => (bool) $validated['done']]);

        return response()->json(['data' => ['text_id' => $item['key'], 'done' => (bool) $validated['done']]]);
    }

    public function vocabulary(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $catalogs = FunkTraining::catalogsFor($course);
        $catalog = $request->query('catalog');
        if ($catalog === null || $catalog === '') {
            if (count($catalogs) !== 1) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['catalog' => ['Bitte den Funkschein SRC oder UBI auswählen.']]);
            }
            $catalog = $catalogs[0];
        }
        if (! in_array($catalog, $catalogs, true)) {
            throw ApiProblem::notFound('Funkkatalog');
        }
        $categories = FunkTraining::vocabulary($catalog);
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if ($category !== null && ! array_key_exists($category, $categories)) {
            throw ApiProblem::notFound('Vokabelkategorie');
        }

        $selected = $category !== null ? [$category => $categories[$category]] : $categories;
        $progress = $this->progress($request, RadioTrainingProgress::TYPE_VOCAB, collect($selected)->flatten(1)->pluck('key')->all());
        $cards = [];
        foreach ($selected as $name => $items) {
            foreach ($items as $item) {
                $saved = $progress->get($item['key']);
                $cards[] = [
                    'id' => $item['key'], 'catalog' => $catalog, 'category' => $name,
                    'word' => $item['de'], 'translation' => $item['en'], 'hint' => $item['hint'] ?? null,
                    'streak' => (int) ($saved?->streak ?? 0), 'learned' => (bool) ($saved?->completed ?? false),
                ];
            }
        }

        return response()->json(['data' => $cards, 'meta' => [
            'catalog' => $catalog, 'category' => $category, 'total' => count($cards),
            'learned' => count(array_filter($cards, fn (array $card) => $card['learned'])),
            'streak_to_learn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN,
        ]]);
    }

    /**
     * Vokabel-Quiz wie im Web: "gewusst" erhöht die Serie, "nicht gewusst"
     * setzt sie zurück, ab VOCAB_STREAK_TO_LEARN gilt sie als gelernt.
     * Optional `streak` = der Stand, den die App kennt: weicht er ab (doppelt
     * gesendet), bleibt der Stand unverändert (repeated=true).
     */
    public function answerVocabulary(Request $request, string $courseId, string $cardId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $validated = $request->validate(['known' => ['required', 'boolean'], 'streak' => ['nullable', 'integer', 'min:0']]);
        $catalogKeys = null;
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $keys = collect(FunkTraining::vocabulary($catalog))->flatten(1)->pluck('key')->all();
            if (in_array($cardId, $keys, true)) {
                $catalogKeys = $keys;
                break;
            }
        }
        if ($catalogKeys === null) {
            throw ApiProblem::notFound('Vokabel');
        }

        $tenantId = $this->tenantContext->id();
        $userId = $request->user()->id;
        $result = DB::transaction(function () use ($tenantId, $userId, $cardId, $validated): array {
            $row = RadioTrainingProgress::where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('item_type', RadioTrainingProgress::TYPE_VOCAB)->where('item_key', $cardId)
                ->lockForUpdate()->first();
            $current = (int) ($row?->streak ?? 0);
            if (isset($validated['streak']) && (int) $validated['streak'] !== $current) {
                return ['streak' => $current, 'learned' => (bool) ($row?->completed ?? false), 'repeated' => true];
            }
            $streak = $validated['known'] ? $current + 1 : 0;
            $learned = (bool) ($row?->completed ?? false) || $streak >= RadioTrainingProgress::VOCAB_STREAK_TO_LEARN;
            RadioTrainingProgress::record($tenantId, $userId, RadioTrainingProgress::TYPE_VOCAB, $cardId, ['streak' => $streak, 'completed' => $learned]);

            return ['streak' => $streak, 'learned' => $learned, 'repeated' => false];
        });

        return response()->json(['data' => ['card_id' => $cardId, 'known' => (bool) $validated['known']] + $result + [
            'learned_count' => $this->progress($request, RadioTrainingProgress::TYPE_VOCAB, $catalogKeys)->where('completed', true)->count(),
        ]]);
    }

    public function practiceIndex(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $progress = $this->progress($request, RadioTrainingProgress::TYPE_PRAXIS);

        return response()->json(['data' => array_map(
            fn (string $catalog) => ['catalog' => $catalog, 'tasks' => $this->practiceRows($catalog, $progress)],
            FunkTraining::catalogsFor($course)
        )]);
    }

    public function showPractice(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [$catalog, $task] = $this->practiceTask($course, $scenarioId);
        $saved = $this->progress($request, RadioTrainingProgress::TYPE_PRAXIS, [$task['key']])->get($task['key']);
        $tasks = FunkPraxis::tasks($catalog);

        return response()->json(['data' => [
            'id' => $task['key'],
            'course_id' => $course->id,
            'catalog' => $catalog,
            'number' => (int) $task['number'],
            'kind' => $task['kind'],
            'situation_de' => $task['situation_de'],
            'situation_en' => $task['situation_en'] ?? null,
            // Ohne Prüfpunkte und Lösungen -- die kommen erst mit der Auswertung.
            'fields' => array_map(fn (array $field) => array_intersect_key($field, array_flip(['name', 'label', 'placeholder', 'multiline'])), $task['fields']),
            'answers' => (object) ($saved?->answers ?? []),
            'done' => (bool) ($saved?->completed ?? false),
            'previous_id' => $tasks[$task['number'] - 1]['key'] ?? null,
            'next_id' => $tasks[$task['number'] + 1]['key'] ?? null,
        ]]);
    }

    public function evaluatePractice(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [, $task] = $this->practiceTask($course, $scenarioId);
        $validated = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:5000']]);
        $answers = array_intersect_key($validated['answers'], array_flip(array_column($task['fields'], 'name')));
        if (trim(implode('', array_map('strval', $answers))) === '') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answers' => ['Trag zuerst etwas ein, dann kannst du auswerten.']]);
        }
        $this->store($request, RadioTrainingProgress::TYPE_PRAXIS, $task['key'], ['answers' => $answers]);

        $evaluation = FunkPraxis::evaluate($task, $answers);
        $fields = [];
        foreach ($task['fields'] as $field) {
            $fields[$field['name']] = [
                'checks' => $evaluation['fields'][$field['name']] ?? [],
                'solution' => $field['solution'],
                'answer' => $answers[$field['name']] ?? '',
            ];
        }

        return response()->json(['data' => [
            'scenario_id' => $task['key'], 'fields' => $fields, 'passed' => $evaluation['passed'], 'total' => $evaluation['total'],
        ]]);
    }

    public function setPracticeDone(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [, $task] = $this->practiceTask($course, $scenarioId);
        $validated = $request->validate(['done' => ['required', 'boolean']]);
        $this->store($request, RadioTrainingProgress::TYPE_PRAXIS, $task['key'], ['completed' => (bool) $validated['done']]);

        return response()->json(['data' => ['scenario_id' => $task['key'], 'done' => (bool) $validated['done']]]);
    }

    /** Musterlösungen aller Praxisaufgaben (im Web: Lösung unter jeder Aufgabe). */
    public function samples(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);

        return response()->json(['data' => array_map(fn (string $catalog) => [
            'catalog' => $catalog,
            'tasks' => array_values(array_map(fn (array $task) => [
                'id' => $task['key'], 'number' => (int) $task['number'], 'kind' => $task['kind'],
                'fields' => array_map(fn (array $field) => ['name' => $field['name'], 'label' => $field['label'] ?? null, 'solution' => $field['solution']], $task['fields']),
            ], FunkPraxis::tasks($catalog))),
        ], FunkTraining::catalogsFor($course))]);
    }

    /** Kurszugang wie alle V1-Endpunkte; Kurse ohne Funkschein: 404. */
    private function radioCourse(Request $request, string $courseId): CourseDefinition
    {
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId);
        if (FunkTraining::catalogsFor($course) === []) {
            throw ApiProblem::notFound('Funktraining');
        }

        return $course;
    }

    /** @return array<string, array<int, array<string, mixed>>> Typ => Texte eines Funkscheins */
    private function textGroups(string $catalog): array
    {
        $groups = ['translation' => FunkTraining::texts($catalog)];
        if ($catalog === 'UBI') {
            foreach (self::TEXT_TYPES as $module => $type) {
                $groups[$type] = FunkTraining::ubiTexts($module);
            }
        }

        return array_filter($groups);
    }

    /** @return array{item: array<string, mixed>, catalog: string, type: string, siblings: list<array<string, mixed>>} */
    private function textRecord(CourseDefinition $course, string $id): array
    {
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            foreach ($this->textGroups($catalog) as $type => $items) {
                foreach ($items as $item) {
                    if ($item['key'] === $id) {
                        return ['item' => $item, 'catalog' => $catalog, 'type' => $type, 'siblings' => array_values($items)];
                    }
                }
            }
        }
        throw ApiProblem::notFound('Funktext');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function practiceTask(CourseDefinition $course, string $id): array
    {
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            foreach (FunkPraxis::tasks($catalog) as $task) {
                if ($task['key'] === $id) {
                    return [$catalog, $task];
                }
            }
        }
        throw ApiProblem::notFound('Funkpraxis-Aufgabe');
    }

    private function practiceRows(string $catalog, Collection $progress): array
    {
        return array_values(array_map(function (array $task) use ($progress) {
            $saved = $progress->get($task['key']);

            return [
                'id' => $task['key'], 'number' => (int) $task['number'], 'kind' => $task['kind'],
                'teaser' => mb_substr((string) $task['situation_de'], 0, 140),
                'done' => (bool) ($saved?->completed ?? false),
                'evaluated' => ! empty($saved?->answers),
            ];
        }, FunkPraxis::tasks($catalog)));
    }

    private function textSummary(array $item, string $type, ?RadioTrainingProgress $saved): array
    {
        return [
            'id' => $item['key'], 'type' => $type, 'number' => (int) $item['number'], 'direction' => $item['richtung'] ?? null,
            'teaser' => mb_substr(str_replace('*', '', (string) ($item['de'] ?? $item['text'] ?? implode(' ', $item['lines'] ?? []))), 0, 90),
            'done' => (bool) ($saved?->completed ?? false),
        ];
    }

    /** @return Collection<string, RadioTrainingProgress> */
    private function progress(Request $request, string $type, ?array $keys = null): Collection
    {
        return RadioTrainingProgress::forItems($this->tenantContext->id(), $request->user()->id, $type, $keys);
    }

    private function store(Request $request, string $type, string $key, array $values): void
    {
        RadioTrainingProgress::record($this->tenantContext->id(), $request->user()->id, $type, $key, $values);
    }

    /** Web-Adresse auf dem Host der Schule (wie Present für Impressum/Datenschutz). */
    private function webUrl(string $route): string
    {
        return CanonicalUrl::root($this->tenantContext->tenant()).(parse_url($route, PHP_URL_PATH) ?: '');
    }
}
