<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use App\Models\Tenant;
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
    use EscapesMarkdown, Queueable;

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
            default => 'als Teilnehmer',
        };

        $message = (new MailMessage)
            ->subject('Einladung zu '.$school)
            ->greeting('Willkommen!')
            ->line(self::escapeMarkdown($school).' hat für dich '.$roleText.' ein Konto auf der Lernplattform angelegt.')
            ->line('Lege jetzt dein Passwort fest, danach kannst du dich direkt anmelden.')
            ->action('Passwort festlegen', $this->url)
            ->line($this->role === 'owner'
                ? 'Der Link ist 7 Tage gültig. Ist er abgelaufen, nutze auf der Anmeldeseite „Passwort vergessen“ oder wende dich an den Plattform-Support.'
                : 'Der Link ist 7 Tage gültig. Ist er abgelaufen, bitte '.self::escapeMarkdown($school).' um eine neue Einladung oder nutze auf der Anmeldeseite „Passwort vergessen“.');

        return MailTemplates::apply($message, 'account_invitation', ['schule' => $school, 'rolle' => trim(str_replace('als ', '', $roleText))]);
    }
}
