<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\PraxisProgress;
use App\Models\PraxisTask;
use App\Support\QuestionMedia;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Praxistrainer als Karteikarten (wie im Web, siehe PraxisTrainerController):
 * je Karte ein Stand open/practice/known. "known" ist dasselbe wie
 * „Gemeistert“ im Web (praxis_progress.completed), beide Wege halten es synchron.
 */
class PraxisController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'praxis');
        // Probezugang: nur die ersten N Karten.
        $tasks = $this->access->limitItems($tenant, $user, $course, 'praxis', $course->praxisTasks()->with('media')->orderBy('sort_order')->orderBy('id')->get());
        $progress = PraxisProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('praxis_task_id', $tasks->pluck('id'))->get()->keyBy('praxis_task_id');

        $categories = [];
        $known = 0;
        foreach ($tasks as $task) {
            $status = $this->status($progress->get($task->id));
            $isKnown = $status === PraxisProgress::STATUS_KNOWN;
            $known += $isKnown ? 1 : 0;

            $categories[$task->kategorie] ??= ['name' => $task->kategorie, 'known_count' => 0, 'cards' => []];
            $categories[$task->kategorie]['known_count'] += $isKnown ? 1 : 0;
            $categories[$task->kategorie]['cards'][] = [
                'id' => (string) $task->id,
                // In manchen Kategorien ist die Unterkategorie selbst die Lösung (z. B. ein Signal).
                'title' => $task->unterkategorieIsSafeToDisplayBeforeReveal() ? $task->unterkategorie : null,
                'reference' => $task->pruefungsbezug,
                'type' => $task->aufgabentyp,
                'question' => $task->frage,
                'answer' => $task->antwort,
                'explanation' => $task->erklaerung,
                'media' => $this->media($task),
                'source' => $task->quelle,
                'source_url' => $task->quellen_url,
                'status' => $status,
            ];
        }

        return response()->json([
            'data' => array_values($categories),
            'meta' => ['total' => $tasks->count(), 'known' => $known],
        ]);
    }

    public function updateStatus(Request $request, string $courseId, string $cardId): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(PraxisProgress::STATUSES)]]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'praxis');
        $task = $course->praxisTasks()->whereKey($cardId)->first();
        if (! $task) {
            throw ApiProblem::notFound('Praxiskarte');
        }
        if (! $this->access->allowsLimitedItem($tenant, $user, $course, 'praxis', $course->praxisTasks()->orderBy('sort_order')->orderBy('id')->get(['id']), (string) $task->id)) {
            throw CourseAccess::itemLocked();
        }

        PraxisProgress::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'praxis_task_id' => $task->id],
            [
                'status' => $validated['status'],
                'completed' => $validated['status'] === PraxisProgress::STATUS_KNOWN,
                'updated_at' => now(),
            ]
        );

        return response()->json(['data' => ['card_id' => (string) $task->id, 'status' => $validated['status']]]);
    }

    private function status(?PraxisProgress $progress): string
    {
        $status = $progress?->status;
        if (! in_array($status, PraxisProgress::STATUSES, true)) {
            return $progress?->completed ? PraxisProgress::STATUS_KNOWN : PraxisProgress::STATUS_OPEN;
        }

        return $status;
    }

    /** Bild wie im Web: zur Frage (vor dem Aufdecken) oder zur Lösung (role). */
    private function media(PraxisTask $task): array
    {
        $asset = $task->media;
        if (! $asset || ! $asset->storage_path) {
            return [];
        }
        $solution = $task->imageBelongsToSolution();
        // Alt-Text einer Frage-Abbildung darf die Lösung (Unterkategorie) nicht verraten.
        $alt = $asset->alt_text ?: ($solution ? $task->unterkategorie : 'Abbildung zur Aufgabe');

        // Geschützte Bilder nur über die signierte Adresse (wie im Web, siehe QuestionMedia).
        return [Present::image(QuestionMedia::url($asset), $alt, $asset->mime_type) + ['role' => $solution ? 'solution' : 'question']];
    }
}
