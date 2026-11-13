<?php

namespace App\Notifications;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Product;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\GermanTime;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigung nach dem Einlösen (oder Zuweisen) eines Codes: welcher Kurs
 * bzw. welches Produkt freigeschaltet wurde und wie lange er gilt.
 */
class CouponRedeemed extends Notification
{
    // Kurs- und Produktnamen pflegt die Plattform, Schulnamen die Schule.
    use AddsNoReplyHint, EscapesMarkdown, Queueable;

    /** @param array{type: string, course?: CourseDefinition, entitlement?: Entitlement, product?: Product} $result */
    public function __construct(private readonly array $result) {}

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
            return self::withNoReplyHint((new MailMessage)
                ->subject('Dein Code wurde eingelöst')
                ->line('„'.self::escapeMarkdown($r['product']->name).'“ wurde deinem Konto gutgeschrieben.')
                ->action('Zu deinen Produkten', route('products.index')));
        }

        $name = self::escapeMarkdown($r['course']->name);

        $message = (new MailMessage)
            ->subject($r['type'] === 'course_renewal' ? 'Dein Kurszugang wurde verlängert' : 'Dein Kurs ist freigeschaltet')
            ->line($r['type'] === 'course_renewal'
                ? "Dein Zugang zu „{$name}“ wurde verlängert".($until ? " und gilt jetzt bis {$until}." : '.')
                : "Der Kurs „{$name}“ ist jetzt für dich freigeschaltet".($until ? " und gilt bis {$until}." : '.'))
            ->action('Zum Kurs', route('courses.show', $r['course']));

        // Verlängerungen behalten ihren eigenen Betreff; Einleitung/Schluss gelten für beide.
        $template = MailTemplates::apply($message, 'coupon_redeemed', ['kurs' => $r['course']->name]);

        return self::withNoReplyHint($r['type'] === 'course_renewal' ? $template->subject('Dein Kurszugang wurde verlängert') : $template);
    }
}
