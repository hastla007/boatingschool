<?php

namespace App\Notifications;

use App\Notifications\Concerns\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Persönliche Einladung in eine Lerngruppe. Geht an jede gültige Adresse,
 * gleich ob es dazu schon ein Konto gibt -- die Antwort an den Verwalter
 * verrät das deshalb nicht. Eingelöst wird nur mit genau dieser Adresse
 * und als Teilnehmer der Schule, zu der die Gruppe gehört.
 */
class LearningGroupInvitation extends Notification
{
    use EscapesMarkdown, Queueable;

    public function __construct(
        private readonly string $groupName,
        private readonly string $inviter,
        private readonly string $schoolName,
        private readonly string $inviteCode,
        private readonly int $validDays,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $group = self::escapeMarkdown($this->groupName);
        $school = self::escapeMarkdown($this->schoolName);

        return (new MailMessage)
            ->subject('Einladung zur Lerngruppe '.$this->groupName)
            ->greeting('Du bist eingeladen')
            ->line(self::escapeMarkdown($this->inviter).' hat dich zur Lerngruppe „'.$group.'“ bei '.$school.' eingeladen.')
            ->line('Öffne die BoatingSchool-App, wähle '.$school.' und löse innerhalb von '.$this->validDays.' Tagen diesen persönlichen Beitrittscode ein:')
            ->line($this->inviteCode)
            ->line('Der Code gilt nur für diese E-Mail-Adresse und nur bei '.$school.'.');
    }
}
