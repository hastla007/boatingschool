<?php

namespace App\\Notifications;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Notifications\\Messages\\MailMessage;
use Illuminate\\Notifications\\Notification;
use Illuminate\\Support\\Facades\\URL;

class AccountDeletionConfirmation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $requestId,
        private readonly string $confirmKey,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $path = (string) config('mobile_api.deletion_confirmation_path', '/app/konto-loeschen/');
        $url = URL::to($path).'?'.http_build_query([
            'request_id' => $this->requestId,
            'confirm_key' => $this->confirmKey,
        ]);

        return (new MailMessage)
            ->subject('Bitte bestätige deinen Antrag auf Kontolöschung')
            ->greeting('Bestätigung erforderlich')
            ->line('Du hast in der BoatingSchool-App die Löschung deines Kontos beantragt.')
            ->line('Bestätige den Antrag über den folgenden Link. Anschließend wird er von der Bootsschule bearbeitet.')
            ->action('Löschantrag bestätigen', $url)
            ->line('Der Link ist sieben Tage gültig. Wenn du den Antrag nicht gestellt hast, kannst du diese E-Mail ignorieren.');
    }
}
