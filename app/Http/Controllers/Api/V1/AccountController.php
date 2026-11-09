<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ApiDeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Profil (lesend), Schulmitgliedschaften und Gerätesitzungen. */
class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'user' => Present::user($user),
            'memberships' => Present::memberships($user),
        ]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->attributes->get('api_session');
        $sessions = ApiDeviceSession::where('user_id', $request->user()->id)->whereNull('revoked_at')
            ->where('max_expires_at', '>', now())->where('refresh_expires_at', '>', now())
            ->orderByDesc('last_used_at')->get();

        return response()->json(['data' => $sessions->map(fn (ApiDeviceSession $s) => [
            'id' => $s->id,
            'device_name' => $s->device_name,
            'device_platform' => $s->device_platform,
            'app_version' => $s->app_version,
            'created_at' => Present::time($s->created_at),
            'last_used_at' => Present::time($s->last_used_at),
            'current' => $s->id === $current->id,
        ])->values()]);
    }

    public function revokeSession(Request $request, string $sessionId): Response
    {
        $session = ApiDeviceSession::where('id', $sessionId)->where('user_id', $request->user()->id)->whereNull('revoked_at')->first();
        if (! $session) {
            throw ApiProblem::notFound('Sitzung');
        }
        $session->forceFill(['revoked_at' => now()])->save();

        return response()->noContent();
    }
}
