<?php

namespace App\Http\Controllers;

use App\Models\TenantBranding;
use App\Services\AppAccountDeletion;
use App\Support\GermanTime;
use App\Support\PendingAppAccountDeletion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Web-Bestätigung einer in der App beantragten Kontolöschung: Ziel des
 * Links aus der Mail (MOBILE_API_DELETION_CONFIRMATION_PATH auf der
 * zentralen Domain), wenn die App ihn nicht als Universal-/App-Link
 * abfängt. GET zeigt nur an (Link-Vorschauen der Mailprogramme), erst das
 * Formular (POST, CSRF) löscht -- mit derselben Logik wie die App
 * (AppAccountDeletion).
 */
class AppAccountDeletionController extends Controller
{
    public function show(Request $request, AppAccountDeletion $deletions): Response
    {
        $requestId = $request->query('request_id');
        if (($retryAfter = $deletions->throttle((string) $request->ip(), $this->limiterId($requestId))) !== null) {
            return $this->tooManyAttempts($retryAfter);
        }

        $pending = $deletions->find($requestId, $request->query('confirm_key'));
        if (! $pending) {
            return $this->invalid();
        }

        $this->shareBranding($pending);
        $current = $request->user();

        return $this->page(response()->view('app-account-deletion.confirm', [
            'school' => $pending->tenant->name,
            'deletesWholeAccount' => $pending->deletesWholeAccount,
            'expiresAt' => GermanTime::format($pending->deletion->expires_at, 'd.m.Y, H:i'),
            'requestId' => (string) $pending->deletion->id,
            'confirmKey' => (string) $request->query('confirm_key'),
            'otherPersonSignedIn' => $current && (string) $current->getAuthIdentifier() !== (string) $pending->user->id,
        ]));
    }

    public function confirm(Request $request, AppAccountDeletion $deletions): Response
    {
        $requestId = $request->input('request_id');
        if (($retryAfter = $deletions->throttle((string) $request->ip(), $this->limiterId($requestId))) !== null) {
            return $this->tooManyAttempts($retryAfter);
        }

        $pending = $deletions->find($requestId, $request->input('confirm_key'));
        // Gelöscht wird das Konto aus dem Antrag (der Link ist der Nachweis),
        // nie das der gerade angemeldeten Person.
        $accountDeleted = $pending ? $deletions->confirm($pending, 'web') : null;
        if ($accountDeleted === null) {
            return $this->invalid();
        }

        // Eigene Web-Sitzung beenden, wenn das Konto ganz entfällt (die
        // gespeicherten Sitzungen löscht AccountErasureService bereits).
        $current = Auth::guard('web')->user();
        if ($accountDeleted && $current && (string) $current->getAuthIdentifier() === (string) $pending->user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->shareBranding($pending);

        return $this->page(response()->view('app-account-deletion.result', [
            'heading' => $accountDeleted ? 'Dein Konto wurde gelöscht' : 'Dein Konto bei '.$pending->tenant->name.' wurde gelöscht',
            'message' => $accountDeleted
                ? 'Deine Mitgliedschaft bei '.$pending->tenant->name.', alle Lerndaten und dein Konto sind gelöscht. Du wurdest überall abgemeldet.'
                : 'Deine Mitgliedschaft und alle Lerndaten bei dieser Bootsschule sind gelöscht. Bei deinen anderen Bootsschulen bleibt dein Konto bestehen.',
            'success' => true,
        ]));
    }

    /** Einheitliche Antwort für unbekannte, abgelaufene, benutzte oder falsche Links. */
    private function invalid(): Response
    {
        return $this->page(response()->view('app-account-deletion.result', [
            'heading' => 'Link ungültig',
            'message' => 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Falls du dein Konto weiterhin löschen möchtest, beantrage die Löschung bitte erneut in der App.',
            'success' => false,
        ], 404));
    }

    private function tooManyAttempts(int $retryAfter): Response
    {
        return $this->page(response()->view('app-account-deletion.result', [
            'heading' => 'Zu viele Versuche',
            'message' => 'Bitte warte etwas und öffne den Link aus der E-Mail dann erneut.',
            'success' => false,
        ], 429)->header('Retry-After', (string) $retryAfter));
    }

    /** Der Link enthält einen geheimen Schlüssel: nicht zwischenspeichern, nicht indexieren. */
    private function page(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Gestaltung der Schule aus dem Antrag (die zentrale Domain hat sonst keine). */
    private function shareBranding(PendingAppAccountDeletion $pending): void
    {
        view()->share('currentTenant', $pending->tenant);
        view()->share('branding', TenantBranding::on('pgsql_admin')->find($pending->tenant->id));
    }

    /** Schlüssel für das Kontingent je Antrag (begrenzt, auch bei beliebigen Eingaben). */
    private function limiterId(mixed $requestId): string
    {
        return is_string($requestId) ? substr($requestId, 0, 64) : '';
    }
}
