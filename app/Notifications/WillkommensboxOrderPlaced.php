<?php

namespace App\Notifications;

use App\Models\WillkommensboxOrder;
use App\Notifications\Concerns\EscapesMarkdown;
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
    use EscapesMarkdown, Queueable;

    public function __construct(private readonly WillkommensboxOrder $order) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Bug-Review (Markdown-Injection über Name/Lieferadresse): Name und
        // Lieferadresse sind vom Lerner frei befüllbar und landeten bisher
        // ungeschützt in einer Markdown-Zeile -- "[Hier bestätigen](https://...)"
        // als Straße erschien in der Admin-Mail als klickbarer Link (siehe
        // EscapesMarkdown).
        $learnerName = self::escapeMarkdown($this->order->user->name ?? 'Ein Teilnehmer');
        $address = self::escapeMarkdown($this->order->street.', '.$this->order->postal_code.' '.$this->order->city.', '.$this->order->country);

        return (new MailMessage)
            ->subject('Neue Willkommensbox-Bestellung')
            ->line("{$learnerName} hat eine Willkommensbox bestellt.")
            ->line('Lieferadresse: '.$address)
            ->action('Zur Teilnehmer-Übersicht', route('admin.participants.show', $this->order->user_id));
    }
}
