<?php

namespace App\Notifications;

use App\Models\WillkommensboxOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review F5: eine neue Willkommensbox-Bestellung löste bisher keine
 * Mail an die Bootsschule aus -- sie bemerkte den Versandbedarf nur, wenn
 * jemand aktiv in der Teilnehmerliste nachsah. Geht an owner/admin der
 * Bootsschule (siehe Tenant::admins()).
 */
class WillkommensboxOrderPlaced extends Notification
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
        $learnerName = $this->order->user->name ?? 'Ein Teilnehmer';

        return (new MailMessage)
            ->subject('Neue Willkommensbox-Bestellung')
            ->line("{$learnerName} hat eine Willkommensbox bestellt.")
            ->line('Lieferadresse: '.$this->order->street.', '.$this->order->postal_code.' '.$this->order->city.', '.$this->order->country)
            ->action('Zur Teilnehmer-Übersicht', route('admin.participants.show', $this->order->user_id));
    }
}
