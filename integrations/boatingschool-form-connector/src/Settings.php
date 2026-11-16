<?php

/**
 * Einstellungen des Connectors. Adresse, Schlüssel-ID und Geheimnis nur serverseitig: bevorzugt als Konstanten
 * in wp-config.php, sonst in der eigenen Option `boatingschool_form_settings` (autoload = no). Das Geheimnis wird
 * nie ausgegeben (im Admin nur maskiert, write-only), nie protokolliert und nie im Formular-Export gespeichert.
 *
 *   define('BOATINGSCHOOL_FORM_ENDPOINT', 'https://app.boatingschool.example');  // Basisadresse der Plattform
 *   define('BOATINGSCHOOL_FORM_KEY_ID', 'fc-lead-2026-11');
 *   define('BOATINGSCHOOL_FORM_SECRET', '…');
 *   define('BOATINGSCHOOL_FORM_ALLOWED_HOSTS', 'www.boatingschool.example');     // erlaubte Quell-URLs (Hosts)
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Settings
{
    public const OPTION = 'boatingschool_form_settings';

    /** Pfad des Endpunkts unter der Basisadresse (Vertrag A.1). */
    public const LEADS_PATH = '/api/integrations/v1/form-captain/leads';

    public const PING_PATH = '/api/integrations/v1/form-captain/ping';

    /** @return array<string,mixed> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    private static function value(string $constant, string $key): string
    {
        if (defined($constant)) {
            return trim((string) constant($constant));
        }

        return trim((string) (self::stored()[$key] ?? ''));
    }

    /** Ob ein Wert aus wp-config.php kommt (dann im Admin nicht änderbar). */
    public static function from_constant(string $key): bool
    {
        return defined(['endpoint' => 'BOATINGSCHOOL_FORM_ENDPOINT', 'key_id' => 'BOATINGSCHOOL_FORM_KEY_ID', 'secret' => 'BOATINGSCHOOL_FORM_SECRET', 'allowed_hosts' => 'BOATINGSCHOOL_FORM_ALLOWED_HOSTS'][$key] ?? '');
    }

    /** Basisadresse der Plattform, nur HTTPS (http nur für localhost/127.0.0.1 in Tests). */
    public static function endpoint(): string
    {
        $url = rtrim(self::value('BOATINGSCHOOL_FORM_ENDPOINT', 'endpoint'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $local = in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1'], true);

        return $scheme === 'https' || ($scheme === 'http' && $local) ? $url : '';
    }

    public static function key_id(): string
    {
        $id = self::value('BOATINGSCHOOL_FORM_KEY_ID', 'key_id');

        return preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $id) === 1 ? $id : '';
    }

    public static function secret(): string
    {
        return self::value('BOATINGSCHOOL_FORM_SECRET', 'secret');
    }

    public static function is_configured(): bool
    {
        return self::endpoint() !== '' && self::key_id() !== '' && strlen(self::secret()) >= 16;
    }

    /** „••••1234“ – nie das Geheimnis selbst. */
    public static function masked_secret(): string
    {
        $secret = self::secret();

        return $secret === '' ? '' : '••••'.substr($secret, -4);
    }

    /**
     * Erlaubte Hosts für Quell-URLs (Website und Formularseite). Standard: der Host der eigenen Website.
     *
     * @return string[]
     */
    public static function allowed_hosts(): array
    {
        $configured = self::value('BOATINGSCHOOL_FORM_ALLOWED_HOSTS', 'allowed_hosts');
        $hosts = $configured === '' ? [(string) parse_url(home_url('/'), PHP_URL_HOST)] : preg_split('/[\s,]+/', $configured);

        return array_values(array_unique(array_filter(array_map(static fn ($h): string => strtolower(trim((string) $h)), (array) $hosts))));
    }

    /** Ob eine URL (http/https) auf einem erlaubten Host liegt. */
    public static function url_allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) $parts['host']), self::allowed_hosts(), true);
    }

    /** Löschung in Form Captain auch in der Plattform löschen (Vertrag A.8). Standard: an. */
    public static function remote_delete(): bool
    {
        if (defined('BOATINGSCHOOL_FORM_REMOTE_DELETE')) {
            return (bool) constant('BOATINGSCHOOL_FORM_REMOTE_DELETE');
        }

        return (string) (self::stored()['remote_delete'] ?? 'on') !== 'off';
    }

    /**
     * Speichern aus dem Admin. Leeres Geheimnis = unverändert (write-only). Werte aus Konstanten bleiben unberührt.
     *
     * @param  array<string,mixed>  $input
     */
    public static function save(array $input): void
    {
        $stored = self::stored();
        foreach (['endpoint', 'key_id', 'allowed_hosts'] as $key) {
            if (array_key_exists($key, $input) && ! self::from_constant($key)) {
                $stored[$key] = trim(sanitize_text_field((string) $input[$key]));
            }
        }
        $secret = trim((string) ($input['secret'] ?? ''));
        if ($secret !== '' && ! self::from_constant('secret')) {
            $stored['secret'] = $secret;
        }
        if (! empty($input['secret_clear']) && ! self::from_constant('secret')) {
            unset($stored['secret']);
        }
        $stored['remote_delete'] = empty($input['remote_delete']) ? 'off' : 'on';
        update_option(self::OPTION, $stored, false);
    }
}
