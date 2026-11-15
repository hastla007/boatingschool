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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Profil (lesend), Schulmitgliedschaften, App-Einstellungen und Gerätesitzungen. */
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

    /** App-Einstellungen des Kontos (geräteübergreifend, schulübergreifend). */
    public function settings(Request $request): JsonResponse
    {
        $row = DB::table('api_user_setting')->where('user_id', $request->user()->id)->first();

        return $this->settingsResponse($row);
    }

    /** Ersetzt die Einstellungen komplett; optional mit Versionsprüfung (If-Match/expected_version). */
    public function updateSettings(Request $request): JsonResponse
    {
        // "present" statt "required": ein leeres Objekt setzt die Einstellungen zurück.
        $validated = $request->validate([
            'settings' => ['present', 'array'],
            'expected_version' => ['nullable', 'integer', 'min:0'],
        ]);
        // Werte unverändert aus dem Rohinhalt übernehmen -- TrimStrings/
        // ConvertEmptyStringsToNull würden gespeicherte Texte sonst verändern.
        $raw = json_decode($request->getContent(), true);
        $settings = is_array($raw) && is_array($raw['settings'] ?? null) ? $raw['settings'] : $validated['settings'];
        if (($settings !== [] && array_is_list($settings)) || ! $this->validSettings($settings, 1)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                'settings' => ['Bitte sende ein JSON-Objekt mit gültigen Schlüsseln und höchstens vier Ebenen.'],
            ]);
        }
        try {
            $encoded = $settings === []
                ? '{}'
                : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encoded = false;
        }
        if ($encoded === false || strlen($encoded) > 8192) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                'settings' => ['Die Einstellungen dürfen höchstens 8192 Bytes groß sein.'],
            ]);
        }

        $expected = $validated['expected_version'] ?? null;
        $ifMatch = $request->header('If-Match');
        if ($ifMatch !== null) {
            if (! preg_match('/^"(0|[1-9][0-9]*)"$/', trim($ifMatch), $match)) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                    'If-Match' => ['Bitte eine ETag-Version wie "3" senden.'],
                ]);
            }
            $headerVersion = (int) $match[1];
            if ($expected !== null && (int) $expected !== $headerVersion) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                    'expected_version' => ['Muss mit If-Match übereinstimmen.'],
                ]);
            }
            $expected = $headerVersion;
        }

        $userId = $request->user()->id;
        $row = DB::transaction(function () use ($userId, $encoded, $expected) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['api-user-settings:'.$userId]);
            $current = DB::table('api_user_setting')->where('user_id', $userId)->lockForUpdate()->first();
            $currentVersion = (int) ($current->version ?? 0);
            if ($expected !== null && (int) $expected !== $currentVersion) {
                throw new ApiProblem(
                    409,
                    'VERSION_CONFLICT',
                    'Versionskonflikt',
                    'Die Einstellungen wurden auf einem anderen Gerät geändert.',
                    [],
                    [],
                    ['details' => ['current' => $this->settingsState($current)]]
                );
            }

            $now = now();
            DB::table('api_user_setting')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'settings' => $encoded,
                    'version' => $currentVersion + 1,
                    'updated_at' => $now,
                    'created_at' => $current?->created_at ?? $now,
                ]
            );

            return DB::table('api_user_setting')->where('user_id', $userId)->first();
        });

        return $this->settingsResponse($row);
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

    /** Öffentlich: Erreichbarkeit für Load-Balancer und App (auch im Wartungsmodus). */
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

    private function settingsResponse(?object $row): JsonResponse
    {
        $version = (int) ($row->version ?? 0);

        return response()->json(['data' => $this->settingsState($row)])
            ->header('ETag', '"'.$version.'"');
    }

    private function settingsState(?object $row): array
    {
        $settings = [];
        if ($row !== null) {
            $decoded = is_array($row->settings) ? $row->settings : json_decode((string) $row->settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        return [
            'settings' => (object) $settings,
            'version' => (int) ($row->version ?? 0),
            // DB::table liefert den Zeitstempel als Text.
            'updated_at' => $row?->updated_at ? Present::time(Carbon::parse($row->updated_at)) : null,
        ];
    }

    /** Schlüssel ^[a-z0-9_.-]{1,64}$, höchstens vier Ebenen, nur Skalare/null/Listen/Objekte. */
    private function validSettings(array $value, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            if (! $isList && (! is_string($key) || ! preg_match('/^[a-z0-9_.-]{1,64}$/', $key))) {
                return false;
            }
            if (is_array($item)) {
                if (! $this->validSettings($item, $depth + 1)) {
                    return false;
                }
            } elseif (! is_null($item) && ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
