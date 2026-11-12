<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ApiDeviceSession;
use App\Models\PlatformSetting;
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

    /** Review-Runde 13: alle anderen Geräte auf einmal abmelden. */
    public function revokeOtherSessions(Request $request): Response
    {
        $current = $request->attributes->get('api_session');
        ApiDeviceSession::where('user_id', $request->user()->id)->whereNull('revoked_at')->where('id', '!=', $current->id)
            ->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    /** Public health probe used by load balancers and mobile clients. */
    public function health(): JsonResponse
    {
        return response()->json(['data' => [
            'status' => 'ok',
            'api_version' => 'v1',
            'server_time' => Present::time(now()),
        ]]);
    }

    /** Öffentlich: Mindestversion, Store-Links und Wartung (vor dem Login abrufbar). */
    public function appConfig(): JsonResponse
    {
        $settings = PlatformSetting::query()->first();

        return response()->json(['data' => [
            'api_version' => 'v1',
            'minimum_version' => config('mobile_api.minimum_version'),
            'store_url' => config('mobile_api.store_url'),
            'maintenance' => [
                'active' => (bool) $settings?->maintenance_mode,
                'message' => $settings?->maintenance_mode ? $settings->maintenance_message : null,
            ],
            'server_time' => Present::time(now()),
        ]]);
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
