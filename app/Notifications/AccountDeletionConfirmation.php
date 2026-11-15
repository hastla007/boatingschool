<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\CanonicalUrl;
use App\Support\GermanTime;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigungslink für eine in der App beantragte Kontolöschung. Der Link
 * zeigt auf den Universal-/App-Link-Pfad der App (MOBILE_API_DELETION_
 * CONFIRMATION_PATH) auf der zentralen Domain -- nie auf den Host des
 * auslösenden Requests (Sicherheitsreview S1, siehe CanonicalUrl).
 */
class AccountDeletionConfirmation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $requestId,
        private readonly string $confirmKey,
        private readonly Tenant $tenant,
        private readonly CarbonInterface $expiresAt,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        $path = '/'.ltrim((string) config('mobile_api.deletion_confirmation_path', '/app/konto-loeschen'), '/');

        return CanonicalUrl::root().$path.'?'.http_build_query([
            'request_id' => $this->requestId,
            'confirm_key' => $this->confirmKey,
        ]);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $school = preg_replace('/([\\\\`*_{}\[\]#|>~])/', '\\\\$1', $this->tenant->name);

        return (new MailMessage)
            ->subject('Bitte bestätige die Löschung deines Kontos')
            ->greeting('Bestätigung erforderlich')
            ->line("Du hast in der App die Löschung deines Kontos bei {$school} beantragt.")
            ->line('Mit der Bestätigung werden deine Mitgliedschaft und alle Lerndaten bei dieser Bootsschule sofort gelöscht. Bist du bei keiner anderen Bootsschule mehr angemeldet, wird auch dein Konto gelöscht. Das lässt sich nicht rückgängig machen.')
            ->action('Löschung bestätigen', $this->url())
            ->line('Der Link ist bis '.GermanTime::format($this->expiresAt, 'd.m.Y, H:i').' Uhr gültig. Wenn du die Löschung nicht beantragt hast, ignoriere diese E-Mail und ändere am besten dein Passwort.');
    }
}
