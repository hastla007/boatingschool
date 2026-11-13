<?php

namespace App\Support;

/**
 * Bestätigung zum Mindestalter bzw. Einverständnis der
 * Erziehungsberechtigten (app_user.age_confirmed_at): Pflicht-Checkbox bei
 * der Registrierung, einmalig nachgefragt beim Festlegen des Passworts bzw.
 * beim Annehmen einer Einladung.
 */
final class AgeConfirmation
{
    public const LABEL = 'Ich bin mindestens 16 Jahre alt oder meine Erziehungsberechtigten sind einverstanden.';

    public const MESSAGE = 'Bitte bestätige, dass du mindestens 16 Jahre alt bist oder deine Erziehungsberechtigten einverstanden sind.';
}
