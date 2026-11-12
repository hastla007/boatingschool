<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\LearningGroup;
use App\Models\User;
use App\Services\LearningGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function __construct(private LearningGroupService $groups) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();
        $groups = LearningGroup::with('members.user')->where('owner_user_id', $owner->id)->latest('created_at')->get();
        $data = $groups->map(fn (LearningGroup $group) => $this->groups->payload($group, $owner))->values();
        $seats = $data->pluck('seats');

        return response()->json(['data' => $data, 'meta' => [
            'groups' => $data->count(),
            'seats_total' => $seats->sum('total'),
            'seats_used' => $seats->sum('used'),
        ]]);
    }

    public function show(Request $request, string $groupId): JsonResponse
    {
        $group = $this->groups->owned($request->user(), $groupId)->load('members.user');

        return response()->json(['data' => $this->groups->payload($group, $request->user())]);
    }

    public function invite(Request $request, string $groupId): JsonResponse
    {
        $validated = $request->validate([
            'emails' => ['sometimes', 'array', 'max:200'],
            'emails.*' => ['nullable', 'string', 'max:320'],
            'email' => ['sometimes', 'nullable', 'string', 'max:320'],
        ]);
        $emails = array_merge($validated['emails'] ?? [], [$validated['email'] ?? null]);
        $emails = array_values(array_filter($emails, fn ($email) => is_string($email) && trim($email) !== ''));
        if ($emails === []) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['emails' => ['Bitte gib mindestens eine E-Mail-Adresse ein.']]);
        }
        if (count($emails) > 200) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['emails' => ['Bitte lade höchstens 200 Personen auf einmal ein.']]);
        }
        $group = $this->groups->owned($request->user(), $groupId);
        $result = $this->groups->invite($group, $request->user(), $emails);

        return response()->json(['data' => ['invited' => $result['invited'], 'skipped' => $result['skipped'], 'group' => $result['group']]]);
    }

    public function removeMember(Request $request, string $groupId, string $memberId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);
        $group = $this->groups->owned($request->user(), $groupId);
        $group = $this->groups->removeMember($group, $memberId, (string) ($validated['reason'] ?? ''));
        $group->load('members.user');

        return response()->json(['data' => $this->groups->payload($group, $request->user())]);
    }

    public function regenerateJoinCode(Request $request, string $groupId): JsonResponse
    {
        $group = $this->groups->owned($request->user(), $groupId);
        $group = $this->groups->regenerate($group)->load('members.user');

        return response()->json(['data' => $this->groups->payload($group, $request->user())]);
    }

    public function previewJoin(Request $request, string $token): JsonResponse
    {
        if (preg_match('/^[gi][0-9a-f]{32}$/i', $token) !== 1) {
            throw new ApiProblem(422, 'COUPON_INVALID', 'Einladungslink ungültig');
        }

        return response()->json(['data' => $this->groups->preview(strtolower($token), $request->user())]);
    }
}
