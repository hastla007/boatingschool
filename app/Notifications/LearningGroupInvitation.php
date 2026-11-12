<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LearningGroupInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $groupName,
        private readonly string $inviter,
        private readonly string $inviteCode,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Einladung zur Lerngruppe '.$this->groupName)
            ->greeting('Du bist eingeladen')
            ->line($this->inviter.' hat dich zur Lerngruppe „'.$this->groupName.'“ eingeladen.')
            ->line('Öffne die BoatingSchool-App und löse innerhalb von 30 Tagen diesen persönlichen Beitrittscode ein:')
            ->line($this->inviteCode)
            ->line('Der Code ist nur für die eingeladene E-Mail-Adresse gültig.');
    }
}
