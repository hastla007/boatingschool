<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Models\RadioTrainingProgress;
use App\Support\FunkPraxis;
use App\Support\FunkTraining;
use App\Support\Geraetesimulation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile adapter for the native SRC/UBI radio trainers. Content and checks use the same
 * FunkTraining/FunkPraxis sources as the Laravel web UI; learner state stays tenant scoped.
 */
class RadioController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $catalogs = FunkTraining::catalogsFor($course);
        $texts = [];
        $vocabulary = [];
        $practice = [];

        foreach ($catalogs as $catalog) {
            $items = [];
            foreach (FunkTraining::texts($catalog) as $item) {
                $items[] = $this->textSummary($request, $item, $catalog, 'translation');
            }
            if ($catalog === 'UBI') {
                foreach (['aufnahme' => 'dictation', 'abgabe' => 'spelling'] as $module => $type) {
                    foreach (FunkTraining::ubiTexts($module) as $item) {
                        $items[] = $this->textSummary($request, $item, $catalog, $type);
                    }
                }
            }
            foreach ($items as $item) {
                $texts[$catalog.'|'.$item['type']] ??= ['catalog' => $catalog, 'type' => $item['type'], 'items' => []];
                $texts[$catalog.'|'.$item['type']]['items'][] = $item;
            }

            foreach (FunkTraining::vocabulary($catalog) as $category => $cards) {
                $keys = array_column($cards, 'key');
                $progress = $this->progress($request, RadioTrainingProgress::TYPE_VOCAB, $keys);
                $vocabulary[] = [
                    'catalog' => $catalog,
                    'category' => $category,
                    'total' => count($cards),
                    'learned' => $progress->where('completed', true)->count(),
                ];
            }

            $tasks = FunkPraxis::tasks($catalog);
            $progress = $this->progress($request, RadioTrainingProgress::TYPE_PRAXIS, array_column($tasks, 'key'));
            $rows = [];
            foreach ($tasks as $task) {
                $saved = $progress->get($task['key']);
                $rows[] = [
                    'id' => $task['key'], 'number' => $task['number'], 'kind' => $task['kind'],
                    'teaser' => mb_substr($task['situation_de'], 0, 140),
                    'done' => (bool) ($saved?->completed ?? false), 'evaluated' => $saved?->answers !== null,
                    'locked' => false,
                ];
            }
            $practice[] = ['catalog' => $catalog, 'tasks' => $rows];
        }

        $deviceAvailable = Geraetesimulation::availableFor($request->user(), $course);
        $deviceCatalogs = Geraetesimulation::catalogsFor($course);
        $deviceLessons = Geraetesimulation::lessonsForBrowser($deviceCatalogs);
        $deviceProgress = RadioTrainingProgress::where('tenant_id', $this->tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('item_type', RadioTrainingProgress::TYPE_DEVICE)
            ->where('completed', true)->count();

        return response()->json(['data' => [
            'course_id' => $course->id,
            'catalogs' => $catalogs,
            'texts' => array_values($texts),
            'vocabulary' => $vocabulary,
            'practice' => $practice,
            'device_simulator' => ['available' => $deviceAvailable, 'premium' => false, 'tier' => $deviceAvailable ? 'free' : null, 'web_url' => $deviceAvailable ? route('funk.device.index', $course) : null, 'open_in' => 'browser', 'hint' => $deviceAvailable ? 'Der Funkgerät-Simulator wird im Browser geöffnet.' : null, 'webshop_url' => null, 'lessons' => count($deviceLessons), 'mastered' => $deviceProgress],
        ], 'meta' => ['limit' => null, 'streak_to_learn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN]]);
    }

    public function spelling(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $letters = [];
        foreach (FunkTraining::ALPHABET as $letter => [$word, $hint]) {
            $letters[] = ['letter' => $letter, 'word' => $word, 'hint' => $hint];
        }
        $digits = [];
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $digits[] = ['catalog' => $catalog, 'items' => array_map(
                fn ($digit, $word) => ['digit' => (string) $digit, 'word' => $word, 'spoken' => $word],
                array_keys(FunkTraining::DIGITS), FunkTraining::DIGITS
            )];
        }

        return response()->json(['data' => ['letters' => $letters, 'digits' => $digits, 'catalogs' => FunkTraining::catalogsFor($course)]]);
    }

    public function showText(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $record = $this->textRecord($course, $textId);
        $item = $record['item'];
        $catalog = $record['catalog'];
        $type = $record['type'];
        $progress = $this->progress($request, RadioTrainingProgress::TYPE_TEXT, [$item['key']])->get($item['key']);
        $answers = $progress?->answers ?? [];
        $direction = (string) $request->query('direction', 'de-en');
        if (! in_array($direction, ['de-en', 'en-de'], true)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['direction' => ['Ungültige Übersetzungsrichtung.']]);
        }

        $hidden = $type === 'translation' && empty($answers[$direction]);
        $textDe = $item['de'] ?? ($item['text'] ?? null);
        $textEn = $item['en'] ?? null;
        $lines = $item['lines'] ?? null;
        if ($hidden && $direction === 'de-en') {
            $textEn = null;
        } elseif ($hidden && $direction === 'en-de') {
            $textDe = null;
            $lines = null;
        }

        $ids = array_column($record['siblings'], 'key');
        $index = array_search($item['key'], $ids, true);
        return response()->json(['data' => [
            'id' => $item['key'], 'course_id' => $course->id, 'catalog' => $catalog, 'type' => $type,
            'number' => (int) $item['number'], 'direction' => $item['richtung'] ?? null,
            'translation_direction' => $type === 'translation' ? $direction : null, 'solution_hidden' => $hidden,
            'evaluable' => in_array($type, ['translation', 'dictation'], true),
            'text_de' => $textDe, 'text_en' => $textEn, 'lines' => $lines,
            'answers' => (object) $answers, 'done' => (bool) ($progress?->completed ?? false),
            'previous_id' => $index !== false && $index > 0 ? $ids[$index - 1] : null,
            'next_id' => $index !== false && isset($ids[$index + 1]) ? $ids[$index + 1] : null,
        ]]);
    }

    public function evaluateText(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $record = $this->textRecord($course, $textId);
        $validated = $request->validate([
            'direction' => ['required', 'in:de-en,en-de'],
            'transcript' => ['nullable', 'string', 'max:5000'],
            'translation' => ['nullable', 'string', 'max:5000'],
        ]);
        $item = $record['item'];
        $type = $record['type'];
        $direction = $validated['direction'];
        $answers = $this->progress($request, RadioTrainingProgress::TYPE_TEXT, [$item['key']])->get($item['key'])?->answers ?? [];
        $answers[$direction] = ['transcript' => $validated['transcript'] ?? '', 'translation' => $validated['translation'] ?? ''];
        $this->store($request, RadioTrainingProgress::TYPE_TEXT, $item['key'], ['answers' => $answers]);

        if ($type === 'translation') {
            [$from, $to] = $direction === 'de-en' ? ['de', 'en'] : ['en', 'de'];
            $transcript = FunkTraining::wordMatch((string) $item[$from], (string) $answers[$direction]['transcript']);
            $translation = FunkTraining::wordMatch((string) $item[$to], (string) $answers[$direction]['translation']);
            $solution = ['from' => $item[$from], 'to' => $item[$to]];
        } else {
            $source = (string) ($item['text'] ?? '');
            $transcript = FunkTraining::wordMatch($source, (string) $answers[$direction]['transcript']);
            $translation = ['percent' => 0, 'missing' => []];
            $solution = ['from' => $source, 'to' => null];
        }

        return response()->json(['data' => [
            'text_id' => $textId, 'direction' => $direction, 'transcript' => $transcript,
            'translation' => $translation, 'solution' => $solution, 'answers' => $answers[$direction],
        ]]);
    }

    public function setTextDone(Request $request, string $courseId, string $textId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $record = $this->textRecord($course, $textId);
        $validated = $request->validate(['done' => ['required', 'boolean']]);
        $this->store($request, RadioTrainingProgress::TYPE_TEXT, $record['item']['key'], ['completed' => $validated['done']]);

        return response()->json(['data' => ['text_id' => $textId, 'done' => (bool) $validated['done']]]);
    }

    public function vocabulary(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $catalog = $request->query('catalog');
        if ($catalog === null || $catalog === '') {
            $catalogs = FunkTraining::catalogsFor($course);
            if (count($catalogs) !== 1) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['catalog' => ['Bitte den Funkkatalog SRC oder UBI auswählen.']]);
            }
            $catalog = $catalogs[0];
        }
        if (! in_array($catalog, FunkTraining::catalogsFor($course), true)) {
            throw ApiProblem::notFound('Funkkatalog');
        }
        $categories = FunkTraining::vocabulary($catalog);
        $category = $request->query('category');
        if ($category !== null && $category !== '' && ! array_key_exists($category, $categories)) {
            throw ApiProblem::notFound('Vokabelkategorie');
        }
        $cards = [];
        foreach ($categories as $name => $items) {
            if ($category !== null && $category !== '' && $name !== $category) {
                continue;
            }
            $progress = $this->progress($request, RadioTrainingProgress::TYPE_VOCAB, array_column($items, 'key'));
            foreach ($items as $item) {
                $saved = $progress->get($item['key']);
                $cards[] = [
                    'id' => $item['key'], 'catalog' => $catalog, 'category' => $name,
                    'word' => $item['de'], 'translation' => $item['en'], 'hint' => $item['hint'] ?? null,
                    'streak' => (int) ($saved?->streak ?? 0), 'learned' => (bool) ($saved?->completed ?? false),
                ];
            }
        }
        $learned = count(array_filter($cards, fn ($card) => $card['learned']));

        return response()->json(['data' => $cards, 'meta' => ['catalog' => $catalog, 'category' => $category ?: null, 'total' => count($cards), 'learned' => $learned, 'streak_to_learn' => RadioTrainingProgress::VOCAB_STREAK_TO_LEARN]]);
    }

    public function answerVocabulary(Request $request, string $courseId, string $cardId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $validated = $request->validate(['known' => ['required', 'boolean'], 'streak' => ['nullable', 'integer', 'min:0']]);
        $catalog = null;
        $allKeys = [];
        foreach (FunkTraining::catalogsFor($course) as $candidate) {
            foreach (FunkTraining::vocabulary($candidate) as $items) {
                $allKeys = array_merge($allKeys, array_column($items, 'key'));
                if (in_array($cardId, array_column($items, 'key'), true)) {
                    $catalog = $candidate;
                }
            }
        }
        if ($catalog === null) {
            throw ApiProblem::notFound('Vokabel');
        }

        $result = DB::transaction(function () use ($request, $cardId, $validated): array {
            $query = RadioTrainingProgress::where('tenant_id', $this->tenantContext->id())
                ->where('user_id', $request->user()->id)
                ->where('item_type', RadioTrainingProgress::TYPE_VOCAB)
                ->where('item_key', $cardId)
                ->lockForUpdate();
            $row = $query->first();
            $created = $row === null;
            if ($created) {
                $row = RadioTrainingProgress::create([
                    'tenant_id' => $this->tenantContext->id(), 'user_id' => $request->user()->id,
                    'item_type' => RadioTrainingProgress::TYPE_VOCAB, 'item_key' => $cardId,
                    'streak' => 0, 'completed' => false, 'updated_at' => now(),
                ]);
            }

            $expected = $validated['streak'] ?? null;
            $isRepeat = ! $created && ($expected !== null
                ? (int) $expected !== (int) $row->streak
                : $row->updated_at !== null && $row->updated_at->greaterThanOrEqualTo(now()->subSeconds(2)));
            if ($isRepeat) {
                return ['streak' => (int) $row->streak, 'learned' => (bool) $row->completed, 'repeated' => true];
            }

            $streak = $validated['known'] ? (int) $row->streak + 1 : 0;
            $learned = (bool) $row->completed || $streak >= RadioTrainingProgress::VOCAB_STREAK_TO_LEARN;
            $row->update(['streak' => $streak, 'completed' => $learned, 'updated_at' => now()]);

            return ['streak' => $streak, 'learned' => $learned, 'repeated' => false];
        });

        $learnedCount = $this->progress($request, RadioTrainingProgress::TYPE_VOCAB, $allKeys)->where('completed', true)->count();

        return response()->json(['data' => ['card_id' => $cardId, 'known' => (bool) $validated['known']] + $result + ['learned_count' => $learnedCount]]);
    }

    public function practiceIndex(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $data = [];
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $tasks = FunkPraxis::tasks($catalog);
            $progress = $this->progress($request, RadioTrainingProgress::TYPE_PRAXIS, array_column($tasks, 'key'));
            $rows = [];
            foreach ($tasks as $task) {
                $saved = $progress->get($task['key']);
                $rows[] = [
                    'id' => $task['key'], 'number' => $task['number'], 'kind' => $task['kind'],
                    'teaser' => mb_substr($task['situation_de'], 0, 140), 'done' => (bool) ($saved?->completed ?? false),
                    'evaluated' => is_array($saved?->answers), 'locked' => false,
                ];
            }
            $data[] = ['catalog' => $catalog, 'tasks' => $rows];
        }

        return response()->json(['data' => $data]);
    }

    public function showPractice(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [$catalog, $task] = $this->practiceTask($course, $scenarioId);
        $progress = $this->progress($request, RadioTrainingProgress::TYPE_PRAXIS, [$task['key']])->get($task['key']);
        $items = array_values(FunkPraxis::tasks($catalog));
        $keys = array_column($items, 'key');
        $index = array_search($task['key'], $keys, true);
        $fields = array_map(fn ($field) => array_intersect_key($field, array_flip(['name', 'label', 'placeholder', 'multiline'])), $task['fields']);

        return response()->json(['data' => [
            'id' => $task['key'], 'course_id' => $course->id, 'catalog' => $catalog,
            'number' => (int) $task['number'], 'kind' => $task['kind'], 'situation_de' => $task['situation_de'],
            'situation_en' => $task['situation_en'], 'fields' => $fields, 'answers' => (object) ($progress?->answers ?? []),
            'done' => (bool) ($progress?->completed ?? false), 'previous_id' => $index > 0 ? $keys[$index - 1] : null,
            'next_id' => isset($keys[$index + 1]) ? $keys[$index + 1] : null,
        ]]);
    }

    public function evaluatePractice(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [$catalog, $task] = $this->practiceTask($course, $scenarioId);
        $validated = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:5000']]);
        $fieldNames = array_column($task['fields'], 'name');
        $answers = array_intersect_key($validated['answers'], array_flip($fieldNames));
        if (trim(implode('', array_map('strval', $answers))) === '') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['answers' => ['Trag zuerst etwas ein.']]);
        }
        $this->store($request, RadioTrainingProgress::TYPE_PRAXIS, $task['key'], ['answers' => $answers]);
        $evaluation = FunkPraxis::evaluate($task, $answers);
        foreach ($task['fields'] as $field) {
            $name = $field['name'];
            $evaluation['fields'][$name] = [
                'checks' => $evaluation['fields'][$name] ?? [],
                'solution' => $field['solution'],
                'answer' => $answers[$name] ?? '',
            ];
        }

        return response()->json(['data' => ['scenario_id' => $scenarioId] + $evaluation]);
    }

    public function setPracticeDone(Request $request, string $courseId, string $scenarioId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        [, $task] = $this->practiceTask($course, $scenarioId);
        $validated = $request->validate(['done' => ['required', 'boolean']]);
        $this->store($request, RadioTrainingProgress::TYPE_PRAXIS, $task['key'], ['completed' => $validated['done']]);

        return response()->json(['data' => ['scenario_id' => $scenarioId, 'done' => (bool) $validated['done']]]);
    }

    public function samples(Request $request, string $courseId): JsonResponse
    {
        $course = $this->radioCourse($request, $courseId);
        $data = [];
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $tasks = [];
            foreach (FunkPraxis::tasks($catalog) as $task) {
                $tasks[] = ['id' => $task['key'], 'number' => $task['number'], 'kind' => $task['kind'],
                    'fields' => array_map(fn ($field) => ['name' => $field['name'], 'solution' => $field['solution']], $task['fields'])];
            }
            $data[] = ['catalog' => $catalog, 'tasks' => $tasks];
        }

        return response()->json(['data' => $data]);
    }

    private function radioCourse(Request $request, string $courseId): \App\Models\CourseDefinition
    {
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId, 'funk');
        if (FunkTraining::catalogsFor($course) === []) {
            throw ApiProblem::notFound('Funktraining');
        }

        return $course;
    }

    /** @return array{item:array<string,mixed>,catalog:string,type:string,siblings:list<array<string,mixed>>} */
    private function textRecord(\App\Models\CourseDefinition $course, string $id): array
    {
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $items = [];
            foreach (FunkTraining::texts($catalog) as $item) {
                $items[] = ['item' => $item, 'type' => 'translation'];
            }
            if ($catalog === 'UBI') {
                foreach (['aufnahme' => 'dictation', 'abgabe' => 'spelling'] as $module => $type) {
                    foreach (FunkTraining::ubiTexts($module) as $item) {
                        $items[] = ['item' => $item, 'type' => $type];
                    }
                }
            }
            $siblings = array_column($items, 'item');
            foreach ($items as $entry) {
                if ($entry['item']['key'] === $id) {
                    return ['item' => $entry['item'], 'catalog' => $catalog, 'type' => $entry['type'], 'siblings' => $siblings];
                }
            }
        }
        throw ApiProblem::notFound('Funktext');
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function practiceTask(\App\Models\CourseDefinition $course, string $id): array
    {
        foreach (FunkTraining::catalogsFor($course) as $catalog) {
            $tasks = FunkPraxis::tasks($catalog);
            foreach ($tasks as $task) {
                if ($task['key'] === $id) {
                    return [$catalog, $task];
                }
            }
        }
        throw ApiProblem::notFound('Funkpraxis-Aufgabe');
    }

    private function textSummary(Request $request, array $item, string $catalog, string $type): array
    {
        $saved = $this->progress($request, RadioTrainingProgress::TYPE_TEXT, [$item['key']])->get($item['key']);

        return ['id' => $item['key'], 'number' => (int) $item['number'], 'direction' => $item['richtung'] ?? null,
            'teaser' => mb_substr((string) ($item['de'] ?? $item['text'] ?? implode(' ', $item['lines'] ?? [])), 0, 90),
            'done' => (bool) ($saved?->completed ?? false), 'locked' => false];
    }

    private function progress(Request $request, string $type, array $keys): \Illuminate\Support\Collection
    {
        if ($keys === []) {
            return collect();
        }

        return RadioTrainingProgress::where('tenant_id', $this->tenantContext->id())
            ->where('user_id', $request->user()->id)->where('item_type', $type)->whereIn('item_key', $keys)->get()->keyBy('item_key');
    }

    private function store(Request $request, string $type, string $key, array $values): void
    {
        RadioTrainingProgress::updateOrCreate(
            ['tenant_id' => $this->tenantContext->id(), 'user_id' => $request->user()->id, 'item_type' => $type, 'item_key' => $key],
            $values + ['updated_at' => now()]
        );
    }
}
