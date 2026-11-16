<?php

namespace Tests;

use App\Models\User;
use App\Support\AnswerToken;
use App\Support\RegistrationBotCheck;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Felder, die das echte Registrierungsformular immer mitschickt:
     * signierter Zeitpunkt des Formularaufrufs (Bot-Schutz, hier 10 s alt)
     * und die Pflicht-Checkbox zum Mindestalter.
     */
    protected function registrationFormFields(): array
    {
        return [RegistrationBotCheck::TIMESTAMP => RegistrationBotCheck::token(time() - 10), 'age_confirmed' => '1'];
    }

    /** Kennung je Person einer Antwort, wie sie Web und App ausgeben (SEC-A1). */
    protected function answerToken(User|string $user, ?string $answerId): ?string
    {
        return $answerId === null ? null : AnswerToken::for($user instanceof User ? $user->id : $user, $answerId);
    }

    /**
     * @param  iterable<string>  $answerIds
     * @return list<string>
     */
    protected function answerTokens(User|string $user, iterable $answerIds): array
    {
        return AnswerToken::mapMany($user instanceof User ? $user->id : $user, $answerIds);
    }
}
