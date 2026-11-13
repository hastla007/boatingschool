<?php

namespace App\Notifications;

use App\Models\Entitlement;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\GermanTime;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Erinnerung, dass ein Kurszugang bald abläuft (siehe
 * RemindExpiringEntitlements), mit dem Verlängerungs-Link der Bootsschule.
 */
class EntitlementExpiringSoon extends Notification
{
    use AddsNoReplyHint, EscapesMarkdown, Queueable;

    public function __construct(private readonly Entitlement $entitlement) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tenant = $this->entitlement->tenant;
        $course = self::escapeMarkdown($this->entitlement->course->name);
        $date = GermanTime::format($this->entitlement->valid_until, 'd.m.Y');
        // Links auf die Subdomain der Bootsschule (der Befehl läuft ohne Request).
        $root = CanonicalUrl::root($tenant);
        $renewal = $tenant->branding?->renewal_webshop_link;

        $message = (new MailMessage)
            ->subject("Dein Kurszugang läuft am {$date} ab")
            ->line("Dein Zugang zu „{$course}“ bei ".self::escapeMarkdown($tenant->name)." läuft am {$date} ab.")
            ->line('Danach kannst du den Kurs nicht mehr nutzen, dein Lernstand bleibt aber erhalten.');

        if ($renewal) {
            $message
                ->action('Verlängerungs-Code kaufen', $renewal)
                ->line('Den Verlängerungs-Code löst du danach in der Lernplattform ein: '.$root.route('coupons.redeem', [], false));
        } else {
            $message
                ->line('Für eine Verlängerung wende dich bitte an deine Bootsschule.')
                ->action('Zum Kurs', $root.route('courses.show', $this->entitlement->course, false));
        }

        return self::withNoReplyHint(MailTemplates::apply($message, 'entitlement_expiring', [
            'schule' => $tenant->name, 'kurs' => $this->entitlement->course->name, 'datum' => $date,
        ]));
    }
}
