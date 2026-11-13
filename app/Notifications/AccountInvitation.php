<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Einladung mit neu angelegtem Konto: Schule und Rolle nennen und zum
 * Festlegen des Passworts in die Schule führen (statt der allgemeinen
 * „Passwort zurücksetzen“-Mail mit 60 Minuten Gültigkeit).
 */
class AccountInvitation extends Notification
{
    use AddsNoReplyHint, EscapesMarkdown, Queueable;

    public function __construct(private readonly ?Tenant $tenant, private readonly string $role, public readonly string $url) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $school = $this->tenant?->name ?? PlatformSetting::siteName();
        $roleText = match ($this->role) {
            'owner' => 'als Inhaber',
            'admin' => 'als Admin',
            'instructor' => 'als Ausbilder',
            'superadmin' => 'als Plattform-Administrator',
            // Review-Runde 12: diese Rollen bekamen „als Teilnehmer“.
            'staff' => 'als Mitarbeiter',
            'support' => 'als Support',
            default => 'als Teilnehmer',
        };

        // Team (Inhaber, Admin, Ausbilder, Plattform) wird gesiezt wie im
        // Admin-Bereich, Teilnehmer geduzt wie in der Lernplattform.
        $formal = $this->role !== 'learner';
        $who = match ($this->role) {
            'owner' => 'Für Sie wurde als Inhaber von '.self::escapeMarkdown($school).' ein Konto auf der Lernplattform angelegt.',
            'superadmin' => 'Für Sie wurde ein Konto als Plattform-Administrator angelegt.',
            default => $formal
                ? self::escapeMarkdown($school).' hat für Sie '.$roleText.' ein Konto auf der Lernplattform angelegt.'
                : self::escapeMarkdown($school).' hat für dich '.$roleText.' ein Konto auf der Lernplattform angelegt.',
        };
        $expired = match (true) {
            in_array($this->role, ['owner', 'superadmin'], true) => 'Der Link ist 7 Tage gültig. Ist er abgelaufen, nutzen Sie auf der Anmeldeseite „Passwort vergessen“ oder wenden Sie sich an den Plattform-Support.',
            $formal => 'Der Link ist 7 Tage gültig. Ist er abgelaufen, bitten Sie '.self::escapeMarkdown($school).' um eine neue Einladung oder nutzen Sie auf der Anmeldeseite „Passwort vergessen“.',
            default => 'Der Link ist 7 Tage gültig. Ist er abgelaufen, bitte '.self::escapeMarkdown($school).' um eine neue Einladung oder nutze auf der Anmeldeseite „Passwort vergessen“.',
        };

        $message = (new MailMessage)
            ->subject('Einladung zu '.$school)
            ->greeting('Willkommen!')
            ->line($who)
            ->line($formal ? 'Legen Sie jetzt Ihr Passwort fest, danach können Sie sich direkt anmelden.' : 'Lege jetzt dein Passwort fest, danach kannst du dich direkt anmelden.')
            ->action('Passwort festlegen', $this->url)
            ->line($expired);

        $message = MailTemplates::apply($message, 'account_invitation', ['schule' => $school, 'rolle' => trim(str_replace('als ', '', $roleText))]);

        return self::withNoReplyHint($message, match (true) {
            in_array($this->role, ['owner', 'superadmin'], true) => 'platform',
            $formal => 'team',
            default => 'learner',
        });
    }
}
