<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\ApiAccountDeletionRequest;
use App\Notifications\AccountDeletionConfirmation;
use App\Services\AppAccountDeletion;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Kontolöschung aus der App (Apple-Richtlinie 5.1.1 v), fachlich identisch
 * mit „Konto löschen“ im Web-Profil (ProfileController::destroy): gilt für
 * die gewählte Bootsschule, löscht dort Mitgliedschaft und alle Lerndaten
 * sofort (AccountErasureService::leaveTenant, inkl. Pseudonymisierung des
 * Protokolls) und das Konto selbst, wenn keine weitere Mitgliedschaft
 * bleibt -- dann enden auch alle App- und Web-Sitzungen. Statt der
 * Passwortabfrage im Web bestätigt die Person per Link aus einer Mail.
 */
class AccountDeletionController extends Controller
{
    /** Gültigkeit des Bestätigungslinks (auch Angabe in der Datenschutzerklärung, PrivacyNotices). */
    public const CONFIRMATION_DAYS = 7;

    public function requestDeletion(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user();
        $tenant = $tenantContext->tenant();

        // Plattform-Konten (Support) löscht nur der Plattformbetreiber (wie im Admin-Bereich).
        if ($user->is_superadmin) {
            throw new ApiProblem(403, 'ACCOUNT_DELETION_NOT_ALLOWED', 'Löschung nicht möglich', 'Dieses Konto gehört zum Plattform-Support und kann nicht in der App gelöscht werden.');
        }

        $limit = 'api-deletion-request:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Löschanträge', 'Bitte versuche es morgen erneut.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($limit),
            ]);
        }
        RateLimiter::hit($limit, 86400);

        $key = 'bsd_'.Str::random(48);
        [$deletion, $alreadyRequested] = DB::transaction(function () use ($user, $tenant, $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['mobile-account-deletion:'.$tenant->id.':'.$user->id]);
            ApiAccountDeletionRequest::where('user_id', $user->id)->where('expires_at', '<=', now())->delete();
            // Erneuter Antrag (z. B. Mail nicht angekommen): neuer Schlüssel und neue
            // Mail, der alte Link wird ungültig; die Frist läuft weiter.
            $existing = ApiAccountDeletionRequest::where('user_id', $user->id)->latest('requested_at')->first();
            if ($existing) {
                $existing->forceFill(['confirm_key_hash' => hash('sha256', $key)])->save();

                return [$existing, true];
            }

            return [ApiAccountDeletionRequest::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'confirm_key_hash' => hash('sha256', $key),
                'requested_at' => now(),
                'expires_at' => now()->addDays(self::CONFIRMATION_DAYS),
            ]), false];
        });

        BestEffortMail::send(
            fn () => $user->notify(new AccountDeletionConfirmation((string) $deletion->id, $key, $tenant, $deletion->expires_at)),
            'mobile account deletion confirmation'
        );

        return response()->json(['data' => [
            'request_id' => (string) $deletion->id,
            'status' => 'pending_confirmation',
            'already_requested' => $alreadyRequested,
            'expires_at' => $deletion->expires_at->toIso8601ZuluString(),
            'message' => 'Wir haben dir eine E-Mail geschickt. Bitte bestätige darin die Löschung deines Kontos.',
        ]], 202);
    }

    /**
     * Öffentlich: die App ruft das mit den Werten aus dem Bestätigungslink auf.
     * Ohne App öffnet der Link die Web-Bestätigungsseite (AppAccountDeletionController);
     * beide nutzen dieselbe Prüfung und Löschung (AppAccountDeletion).
     */
    public function confirmDeletion(Request $request, AppAccountDeletion $deletions): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['bail', 'required', 'uuid'],
            'confirm_key' => ['bail', 'required', 'string', 'max:100'],
        ]);
        if (($retryAfter = $deletions->throttle((string) $request->ip(), $validated['request_id'])) !== null) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Bestätigungsversuche', 'Bitte später erneut versuchen.', [], [
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        $invalid = new ApiProblem(
            422,
            'DELETION_CONFIRMATION_INVALID',
            'Bestätigungslink ungültig',
            'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte beantrage die Löschung in der App erneut.'
        );

        $pending = $deletions->find($validated['request_id'], $validated['confirm_key']);
        $accountDeleted = $pending ? $deletions->confirm($pending, 'app') : null;
        if ($accountDeleted === null) {
            throw $invalid;
        }

        return response()->json(['data' => [
            'request_id' => (string) $pending->deletion->id,
            'status' => 'completed',
            'account_deleted' => $accountDeleted,
            'message' => $accountDeleted
                ? 'Dein Konto wurde gelöscht.'
                : 'Dein Konto bei dieser Bootsschule wurde gelöscht. Bei deinen anderen Bootsschulen bleibst du angemeldet.',
        ]]);
    }
}
