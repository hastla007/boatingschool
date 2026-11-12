<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\ApiAccountDeletionRequest;
use App\Notifications\AccountDeletionConfirmation;
use App\Support\BestEffortMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AccountDeletionController extends Controller
{
    private const CONFIRMATION_DAYS = 7;

    /** Start the same confirmed, administrator-processed deletion workflow as the WP app API. */
    public function requestDeletion(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = 'api-deletion-request:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Löschanträge', 'Bitte versuche es morgen erneut.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($limit),
            ]);
        }
        RateLimiter::hit($limit, 86400);

        $key = Str::random(64);
        $row = DB::transaction(function () use ($user, $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['mobile-account-deletion:'.$user->id]);
            $existing = ApiAccountDeletionRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending_confirmation', 'confirmed'])
                ->latest('requested_at')->lockForUpdate()->first();
            if ($existing) {
                return [$existing, true];
            }

            return [ApiAccountDeletionRequest::create([
                'user_id' => $user->id,
                'confirm_key_hash' => hash('sha256', $key),
                'status' => 'pending_confirmation',
                'requested_at' => now(),
                'expires_at' => now()->addDays(self::CONFIRMATION_DAYS),
            ]), false];
        });

        [$deletion, $alreadyRequested] = $row;
        if (! $alreadyRequested) {
            BestEffortMail::send(
                fn () => $user->notify(new AccountDeletionConfirmation((string) $deletion->id, $key)),
                'mobile account deletion confirmation'
            );
        }

        return response()->json(['data' => $this->payload($deletion, $alreadyRequested)], 202);
    }

    /** Public confirmation endpoint reached from the confirmation email/app link. */
    public function confirmDeletion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['bail', 'required', 'uuid'],
            'confirm_key' => ['bail', 'required', 'string', 'max:100'],
        ]);
        $ipLimit = 'api-deletion-confirm-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipLimit, 20)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Bestätigungsversuche', 'Bitte später erneut versuchen.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($ipLimit),
            ]);
        }
        RateLimiter::hit($ipLimit, 3600);
        $requestLimit = 'api-deletion-confirm:'.$validated['request_id'];
        if (RateLimiter::tooManyAttempts($requestLimit, 10)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Bestätigungsversuche', 'Bitte später erneut versuchen.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($requestLimit),
            ]);
        }
        RateLimiter::hit($requestLimit, 3600);

        $invalid = fn () => new ApiProblem(
            422,
            'DELETION_CONFIRMATION_INVALID',
            'Bestätigungslink ungültig',
            'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte beantrage die Löschung in der App erneut.'
        );
        $deletion = ApiAccountDeletionRequest::whereKey($validated['request_id'])->first();
        if (! $deletion || $deletion->status !== 'pending_confirmation'
            || $deletion->expires_at->isPast()
            || ! hash_equals((string) $deletion->confirm_key_hash, hash('sha256', $validated['confirm_key']))) {
            throw $invalid();
        }

        $deletion->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();

        return response()->json(['data' => $this->payload($deletion, false)]);
    }

    private function payload(ApiAccountDeletionRequest $deletion, bool $alreadyRequested): array
    {
        $confirmed = $deletion->status === 'confirmed';

        return [
            'request_id' => (string) $deletion->id,
            'status' => $confirmed ? 'confirmed' : 'pending_confirmation',
            'already_requested' => $alreadyRequested,
            'message' => $confirmed
                ? 'Du hast die Löschung bereits bestätigt. Wir bearbeiten deinen Antrag und melden uns per E-Mail.'
                : 'Wir haben dir eine E-Mail geschickt. Bitte bestätige darin die Löschung deines Kontos.',
        ];
    }
}
