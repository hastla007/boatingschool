<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\SentFromSchool;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Erinnerung der Bootsschule an Lernende, die länger nicht gelernt haben.
 * Audit ARC-2: „an alle erinnern“ über die Queue, einzelne Erinnerungen mit
 * Rückmeldung weiter direkt (notifyNow).
 */
class LearningReminder extends Notification implements ShouldQueue
{
    use EscapesMarkdown, Queueable, SentFromSchool;

    public function __construct(Tenant $tenant, private readonly ?int $days)
    {
        $this->captureSchool($tenant->id);
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return $this->school() !== null;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tenant = $this->school();
        $school = $tenant->name;
        $message = (new MailMessage)
            ->subject("Weiterlernen bei {$school}")
            ->line($this->days
                ? "Du hast seit {$this->days} Tagen nicht mehr gelernt. Schon ein paar Fragen am Tag bringen dich deiner Prüfung ein gutes Stück näher."
                : 'Du hast noch nicht mit dem Lernen angefangen. Schon ein paar Fragen am Tag bringen dich deiner Prüfung ein gutes Stück näher.')
            // Review-Runde 12: kein eigener Gruß mehr -- die Unterschrift der Mail nennt die Schule.
            ->action('Weiterlernen', CanonicalUrl::root($tenant).route('dashboard', [], false));

        return MailTemplates::apply($message, 'learning_reminder', ['schule' => $school, 'tage' => $this->days ? (string) $this->days : '']);
    }
}
