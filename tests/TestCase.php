<?php

namespace Tests;

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
}
