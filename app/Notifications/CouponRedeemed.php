<?php

namespace App\Notifications;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Product;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\SentFromSchool;
use App\Support\CanonicalUrl;
use App\Support\GermanTime;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigung nach dem Einlösen (oder Zuweisen) eines Codes: welcher Kurs
 * bzw. welches Produkt freigeschaltet wurde und wie lange er gilt.
 *
 * Audit ARC-2: Freischaltungen durch die Schule (auch Sammel-Verlängerung)
 * gehen über die Queue (AccessGrantedMail); das selbst Einlösen schickt sie
 * weiter direkt (notifyNow). Die Modelle stecken im Array und werden mit
 * ihren Werten serialisiert (kein Nachladen unter RLS im Worker).
 */
class CouponRedeemed extends Notification implements ShouldQueue
{
    // Kurs- und Produktnamen pflegt die Plattform, Schulnamen die Schule.
    use EscapesMarkdown, Queueable, SentFromSchool;

    /** @param array{type: string, course?: CourseDefinition, entitlement?: Entitlement, product?: Product} $result */
    public function __construct(private readonly array $result)
    {
        $this->captureSchool(isset($result['entitlement']) ? $result['entitlement']->tenant_id : null);
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $r = $this->result;
        $until = isset($r['entitlement']) ? GermanTime::format($r['entitlement']->valid_until, 'd.m.Y') : null;

        if ($r['type'] === 'product') {
            return (new MailMessage)
                ->subject('Dein Code wurde eingelöst')
                ->line('„'.self::escapeMarkdown($r['product']->name).'“ wurde deinem Konto gutgeschrieben.')
                ->action('Zu deinem Profil', CanonicalUrl::root($this->school()).route('profile.edit', [], false));
        }

        $name = self::escapeMarkdown($r['course']->name);

        $message = (new MailMessage)
            ->subject($r['type'] === 'course_renewal' ? 'Dein Kurszugang wurde verlängert' : 'Dein Kurs ist freigeschaltet')
            ->line($r['type'] === 'course_renewal'
                ? "Dein Zugang zu „{$name}“ wurde verlängert".($until ? " und gilt jetzt bis {$until}." : '.')
                : "Der Kurs „{$name}“ ist jetzt für dich freigeschaltet".($until ? " und gilt bis {$until}." : '.'))
            // Links unter der Adresse der Schule (im Worker gibt es keinen Request-Host).
            ->action('Zum Kurs', CanonicalUrl::root($this->school()).route('courses.show', $r['course'], false));

        // Verlängerungen behalten ihren eigenen Betreff; Einleitung/Schluss gelten für beide.
        $template = MailTemplates::apply($message, 'coupon_redeemed', ['kurs' => $r['course']->name]);

        return $r['type'] === 'course_renewal' ? $template->subject('Dein Kurszugang wurde verlängert') : $template;
    }
}
