<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\GermanTime;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Vorwarnung vor der Löschung eines inaktiven Lernkontos (siehe
 * app:prune-inactive-learners): wer sich bis zum genannten Datum anmeldet,
 * behält sein Konto.
 */
class InactiveAccountDeletionWarning extends Notification
{
    use AddsNoReplyHint, EscapesMarkdown, Queueable;

    public function __construct(private readonly CarbonInterface $deleteAfter, private readonly int $months, private readonly ?Tenant $tenant = null) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $date = GermanTime::format($this->deleteAfter, 'd.m.Y');
        $root = CanonicalUrl::root($this->tenant);
        $school = $this->tenant ? ' bei '.self::escapeMarkdown($this->tenant->name) : '';

        return self::withNoReplyHint((new MailMessage)
            ->subject("Dein Lernkonto wird am {$date} gelöscht")
            ->line("Du hast dich seit über {$this->months} Monaten nicht mehr in deinem Lernkonto{$school} angemeldet und hast keinen aktiven Kurszugang mehr.")
            ->line("Damit wir deine Daten nicht länger als nötig speichern, löschen wir dein Konto samt Lernstand frühestens am {$date}.")
            ->action('Anmelden und Konto behalten', $root.route('login', [], false))
            ->line('Wenn du dich vorher anmeldest, bleibt dein Konto bestehen. Möchtest du deine Daten vorher sichern, kannst du sie nach der Anmeldung im Profil exportieren.'));
    }
}
