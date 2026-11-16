<?php

/**
 * Bestätigungslink für die E-Mail-Adresse eines neuen Kundenkontos (ohne
 * WordPress-Abhängigkeit, testbar). Erst nach der Bestätigung meldet das
 * Plugin das Konto bei der Plattform an (BS_Accounts).
 *
 * Signiert werden Kunden-ID, Adresse, ein Einmalschlüssel aus den Nutzer-
 * Metadaten (neuer Link = alter ungültig) und der Ablaufzeitpunkt.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Email_Verification
{
    /** Gültigkeit eines Links in Sekunden (3 Tage). */
    const TTL = 259200;

    /** Mindestabstand zwischen zwei Mails auf Anforderung (Sekunden). */
    const RESEND_INTERVAL = 60;

    public static function token(string $secret, int $user_id, string $email, string $key, int $expires): string
    {
        return hash_hmac('sha256', $user_id."\n".strtolower(trim($email))."\n".$key."\n".$expires, $secret);
    }

    public static function verify(string $secret, int $user_id, string $email, string $key, int $expires, string $token, ?int $now = null): bool
    {
        if ($secret === '' || $key === '' || $token === '' || $user_id <= 0) {
            return false;
        }
        if ($expires < ($now ?? time())) {
            return false;
        }

        return hash_equals(self::token($secret, $user_id, $email, $key, $expires), strtolower($token));
    }

    /**
     * Bestätigt? Vom Provisionierungs-Endpunkt angelegte Konten gelten als
     * bestätigt (die Adresse kommt aus der Plattform). Sonst muss die
     * bestätigte Adresse der aktuellen entsprechen -- eine geänderte Adresse
     * ist wieder unbestätigt.
     *
     * @param  array<string, mixed>  $meta  _bs_provisioned, _bs_email_confirmed
     */
    public static function is_confirmed(array $meta, string $current_email): bool
    {
        if (! empty($meta['_bs_provisioned'])) {
            return true;
        }
        $confirmed = strtolower(trim((string) ($meta['_bs_email_confirmed'] ?? '')));

        return $confirmed !== '' && $confirmed === strtolower(trim($current_email));
    }

    /** Darf jetzt erneut eine Mail gesendet werden? */
    public static function can_resend(int $last_sent, ?int $now = null): bool
    {
        return ($now ?? time()) - $last_sent >= self::RESEND_INTERVAL;
    }
}
