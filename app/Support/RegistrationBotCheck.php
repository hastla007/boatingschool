<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Bot-Schutz für die Selbstregistrierung ohne Dienste Dritter (kein
 * Captcha): ein für Menschen unsichtbares Feld, das leer bleiben muss
 * (Honeypot), und eine Mindest-Ausfüllzeit über einen signierten
 * Zeitstempel aus dem Formular. Ein Verdachtsfall bekommt dieselbe
 * Erfolgsantwort wie eine echte Registrierung -- ein Bot erfährt so nicht,
 * woran er erkannt wurde.
 */
final class RegistrationBotCheck
{
    /** Honeypot: echte Nutzer sehen das Feld nicht und lassen es leer. */
    public const HONEYPOT = 'website';

    /** Signierter Zeitpunkt, zu dem das Formular ausgeliefert wurde. */
    public const TIMESTAMP = 'form_started';

    /** Schneller füllt kein Mensch Name, E-Mail und zweimal das Passwort aus. */
    public const MIN_SECONDS = 3;

    public static function token(?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $timestamp.'.'.self::signature($timestamp);
    }

    public static function looksLikeBot(Request $request): bool
    {
        $honeypot = $request->input(self::HONEYPOT);
        if ($honeypot !== null && (! is_string($honeypot) || trim($honeypot) !== '')) {
            return true;
        }

        $token = $request->input(self::TIMESTAMP);
        if (! is_string($token) || ! preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $match)) {
            return true;
        }
        $timestamp = (int) $match[1];
        if (! hash_equals(self::signature($timestamp), $match[2])) {
            return true;
        }

        $elapsed = time() - $timestamp;

        return $elapsed < self::MIN_SECONDS;
    }

    private static function signature(int $timestamp): string
    {
        return hash_hmac('sha256', 'registration-form|'.$timestamp, (string) config('app.key'));
    }
}
