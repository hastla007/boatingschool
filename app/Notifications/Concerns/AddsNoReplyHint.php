<?php

namespace App\Notifications\Concerns;

use App\Support\SchoolMails;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Schlusszeile für Mails einer Bootsschule (SchoolMails::NOTIFICATIONS):
 * ohne bestätigte Support-Adresse der Schule landet eine Antwort bei der
 * Absenderadresse der Plattform, die niemand liest -- die Mail sagt das
 * deshalb selbst. Teilnehmer werden geduzt, das Team gesiezt.
 */
trait AddsNoReplyHint
{
    /** @param 'learner'|'team'|'platform' $audience */
    protected static function withNoReplyHint(MailMessage $message, string $audience = 'learner'): MailMessage
    {
        if (SchoolMails::hasVerifiedReplyTo()) {
            return $message;
        }

        return $message->line(match ($audience) {
            'team' => 'Bitte antworten Sie nicht auf diese E-Mail. Bei Fragen wenden Sie sich direkt an die Bootsschule.',
            // Inhaber einer neuen Schule und Plattform-Administratoren: keine Schule, an die man sich wenden könnte.
            'platform' => 'Bitte antworten Sie nicht auf diese E-Mail – sie wurde automatisch versendet.',
            default => 'Bitte antworte nicht auf diese E-Mail. Bei Fragen wende dich direkt an deine Bootsschule.',
        });
    }
}
