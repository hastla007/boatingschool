<?php

namespace App\Notifications;

use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\GermanTime;
use App\Support\MailPreferences;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Erinnerung, dass ein Kurszugang bald abläuft (siehe
 * RemindExpiringEntitlements), mit dem Verlängerungs-Link der Bootsschule.
 * Bei einem Zugang aus einer Lerngruppe ($group) ein eigener Text ohne
 * Kauf-/Code-Hinweis: die Gruppe verwaltet die Schule, verlängert wird dort.
 */
class EntitlementExpiringSoon extends Notification
{
    use AddsNoReplyHint, EscapesMarkdown, Queueable;

    public function __construct(private readonly Entitlement $entitlement, private readonly ?LearningGroup $group = null) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        // Abbestellbar (§ 7 UWG): ohne Einwilligung keine Mail.
        return MailPreferences::wants($notifiable, MailPreferences::EXPIRY) ? ['mail'] : [];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tenant = $this->entitlement->tenant;
        $course = self::escapeMarkdown($this->entitlement->course->name);
        $date = GermanTime::format($this->entitlement->valid_until, 'd.m.Y');
        // Links auf die Subdomain der Bootsschule (der Befehl läuft ohne Request).
        $root = CanonicalUrl::root($tenant);

        if ($this->group) {
            return $this->groupMail($notifiable, $tenant, $course, $date, $root);
        }

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

        return MailPreferences::addUnsubscribe(self::withNoReplyHint(MailTemplates::apply($message, 'entitlement_expiring', [
            'schule' => $tenant->name, 'kurs' => $this->entitlement->course->name, 'datum' => $date,
        ])), $notifiable, MailPreferences::EXPIRY, $this->entitlement->tenant);
    }

    /** Gruppenzugang: Hinweis auf die Gruppe und die Schule, kein Kauf, kein Code. */
    private function groupMail(mixed $notifiable, Tenant $tenant, string $course, string $date, string $root): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Dein Zugang über die Lerngruppe endet am {$date}")
            ->line("Dein Zugang zu „{$course}“ über die Lerngruppe „".self::escapeMarkdown($this->group->name).'“ bei '
                .self::escapeMarkdown($tenant->name)." endet am {$date}.")
            ->line('Danach kannst du den Kurs nicht mehr nutzen, dein Lernstand bleibt aber erhalten.')
            ->line('Die Lerngruppe verwaltet deine Bootsschule. Für eine Verlängerung wende dich bitte an sie.')
            ->action('Zum Kurs', $root.route('courses.show', $this->entitlement->course, false));

        return MailPreferences::addUnsubscribe(self::withNoReplyHint(MailTemplates::apply($message, 'group_entitlement_expiring', [
            'schule' => $tenant->name, 'kurs' => $this->entitlement->course->name, 'gruppe' => $this->group->name, 'datum' => $date,
        ])), $notifiable, MailPreferences::EXPIRY, $tenant);
    }
}
