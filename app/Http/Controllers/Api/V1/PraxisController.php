<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\PraxisProgress;
use App\Models\PraxisTask;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PraxisController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $tasks = $course->praxisTasks()->where('active', true)->with('media')->orderBy('sort_order')->get();
        $progress = PraxisProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('praxis_task_id', $tasks->pluck('id'))->get()->keyBy('praxis_task_id');

        $categories = [];
        $known = 0;
        foreach ($tasks as $task) {
            $state = $progress->get($task->id);
            $status = $state?->status ?: ($state?->completed ? 'known' : 'open');
            $isKnown = $status === 'known';
            $known += $isKnown ? 1 : 0;

            $card = [
                'id' => (string) $task->id,
                // Some subcategories are themselves the answer (e.g. a light or signal).
                'title' => $task->unterkategorieIsSafeToDisplayBeforeReveal() ? $task->unterkategorie : null,
                'locked' => false,
                'reference' => $task->pruefungsbezug,
                'type' => $task->aufgabentyp,
                'question' => $task->frage,
                'answer' => $task->antwort,
                'explanation' => $task->erklaerung,
                'media' => $this->media($task),
                'source' => $task->quelle,
                'source_url' => $task->quellen_url,
                'status' => in_array($status, ['known', 'practice', 'open'], true) ? $status : 'open',
            ];
            $categories[$task->kategorie] ??= ['name' => $task->kategorie, 'known_count' => 0, 'cards' => []];
            $categories[$task->kategorie]['cards'][] = $card;
            $categories[$task->kategorie]['known_count'] += $isKnown ? 1 : 0;
        }

        return response()->json([
            'data' => array_values($categories),
            'meta' => ['total' => $tasks->count(), 'known' => $known],
        ]);
    }

    public function updateStatus(Request $request, string $courseId, string $cardId): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:known,practice,open']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $task = $course->praxisTasks()->where('active', true)->whereKey($cardId)->first();
        if (! $task) {
            throw ApiProblem::notFound('Praxiskarte');
        }

        PraxisProgress::updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'praxis_task_id' => $task->id],
            [
                'status' => $validated['status'],
                'completed' => $validated['status'] === 'known',
                'updated_at' => now(),
            ]
        );

        return response()->json(['data' => ['card_id' => (string) $task->id, 'status' => $validated['status']]]);
    }

    private function media(PraxisTask $task): array
    {
        $asset = $task->media;
        if (! $asset || $asset->status !== 'published' || ! in_array($asset->rights_status, ['licensed', 'owned', 'public_domain'], true)) {
            return [];
        }

        return [Present::image($asset->storage_path, $asset->alt_text ?: $task->unterkategorie, $asset->mime_type)];
    }
}
