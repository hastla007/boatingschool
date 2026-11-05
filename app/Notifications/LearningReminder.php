<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Erinnerung der Bootsschule an Lernende, die länger nicht gelernt haben. */
class LearningReminder extends Notification
{
    use EscapesMarkdown, Queueable;

    public function __construct(private readonly Tenant $tenant, private readonly ?int $days) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $school = $this->tenant->name;
        $message = (new MailMessage)
            ->subject("Weiterlernen bei {$school}")
            ->line($this->days
                ? "Du hast seit {$this->days} Tagen nicht mehr gelernt. Schon ein paar Fragen am Tag bringen dich deiner Prüfung ein gutes Stück näher."
                : 'Du hast noch nicht mit dem Lernen angefangen. Schon ein paar Fragen am Tag bringen dich deiner Prüfung ein gutes Stück näher.')
            ->action('Weiterlernen', CanonicalUrl::root($this->tenant).route('dashboard', [], false))
            ->line('Viele Grüße von '.self::escapeMarkdown($school).'.');

        return MailTemplates::apply($message, 'learning_reminder', ['schule' => $school, 'tage' => $this->days ? (string) $this->days : '']);
    }
}
