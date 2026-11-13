<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Mails an die Schule, die ein Lernender ausgelöst hat (Praxis-Anmeldung,
 * Box-Bestellung, Terminrückmeldung): „Antworten“ geht an diese Person statt
 * an die Absenderadresse der Plattform.
 */
trait RepliesToLearner
{
    protected static function replyToLearner(MailMessage $message, ?User $learner): MailMessage
    {
        if ($learner?->email && $learner->email_verified_at) {
            $message->replyTo($learner->email, $learner->name);
        }

        return $message;
    }
}
