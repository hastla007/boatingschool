<?php

namespace App\Notifications;

use App\Models\WillkommensboxOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review F5: der Versand der Willkommensbox löste bisher keine Mail
 * an den Lerner aus -- er bemerkte den Versand nur, wenn er von selbst
 * wieder auf die Willkommensbox-Seite sah.
 */
class WillkommensboxOrderShipped extends Notification
{
    use Queueable;

    public function __construct(private readonly WillkommensboxOrder $order) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Deine Willkommensbox ist unterwegs')
            ->line('Deine Willkommensbox wurde versendet.');

        if ($this->order->tracking_number) {
            $message->line('Sendungsnummer: '.$this->order->tracking_number);
        }

        return $message->action('Zur Willkommensbox', route('willkommensbox.index'));
    }
}
