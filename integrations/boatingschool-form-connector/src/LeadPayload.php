<?php

/**
 * Baut den Body form-captain-lead/1 (Vertrag A.3) aus einem Job von Form Captain.
 *
 * Nur explizit gemappte, nicht sensible Felder (Mapping der Aktion, keine allow_sensitive-Einträge), dazu
 * Formular- und Entry-ID, die erlaubte Quell-URL und die Consent-Referenz (Zeitpunkt der Einsendung, Revision und
 * optional Hash des Einwilligungstexts – der Text selbst bleibt in WordPress). Nie: IP, User-Agent, versteckte
 * Felder, interne Notizen, Werte anderer Felder.
 *
 * Der Body ist je Job deterministisch (Zeitpunkte aus dem Entry, nicht „jetzt“): eine Wiederholung schickt denselben
 * Body und bekommt über den Idempotency-Key dieselbe lead_id.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class LeadPayload
{
    public const SCHEMA = 'form-captain-lead/1';

    public const PURPOSES = ['demo', 'consultation', 'school_access_info'];

    public const SCHOOL_SIZES = ['1-2', '3-10', '11-50', '50+'];

    /** Mapping-Key => [Schema-Feld, Höchstlänge]. */
    public const CONTACT_FIELDS = [
        'name' => 120,
        'email' => 190,
        'phone' => 40,
        'organisation' => 160,
        'role' => 80,
    ];

    /**
     * @param  array<string,mixed>  $job  Job-Array des Vertrags v1 (nur gemappte Werte in `values`).
     * @param  string  $submitted_at  Einsendezeitpunkt des Entries (ISO 8601, UTC).
     * @return array{ok:bool,code:string,body:array<string,mixed>}
     */
    public static function build(array $job, string $submitted_at): array
    {
        $values = is_array($job['values'] ?? null) ? $job['values'] : [];
        $settings = is_array($job['settings'] ?? null) ? $job['settings'] : [];

        $email = self::text($values['email'] ?? '', 190);
        if ($email === '' || ! is_email($email)) {
            return ['ok' => false, 'code' => 'invalid_email', 'body' => []];
        }
        if (! self::consent_given($values['consent'] ?? null)) {
            return ['ok' => false, 'code' => 'consent_missing', 'body' => []];
        }
        $revision = (int) ($settings['consent_text_revision'] ?? 0);
        if ($revision < 1) {
            return ['ok' => false, 'code' => 'consent_revision_missing', 'body' => []];
        }
        $site_url = untrailingslashit(home_url('/'));
        if (! Settings::url_allowed($site_url)) {
            return ['ok' => false, 'code' => 'source_url_not_allowed', 'body' => []];
        }
        $page_url = trim((string) ($settings['page_url'] ?? ''));
        if ($page_url !== '' && (! Settings::url_allowed($page_url) || strlen($page_url) > 500)) {
            return ['ok' => false, 'code' => 'source_url_not_allowed', 'body' => []];
        }
        $submitted = self::utc($submitted_at);
        if ($submitted === '') {
            return ['ok' => false, 'code' => 'entry_time_missing', 'body' => []];
        }

        $contact = ['email' => $email];
        foreach (self::CONTACT_FIELDS as $key => $max) {
            if ($key !== 'email') {
                $value = self::text($values[$key] ?? '', $max);
                if ($value !== '') {
                    $contact[$key] = $value;
                }
            }
        }
        $consent = ['given_at' => $submitted, 'text_revision' => $revision];
        $hash = strtolower(trim((string) ($settings['consent_text_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
            $consent['text_hash'] = $hash;
        }
        $source = [
            'site_url' => substr($site_url, 0, 200),
            'form_id' => (int) ($job['form_id'] ?? 0),
            'entry_id' => (int) ($job['entry_id'] ?? 0),
            'plugin_version' => BOATINGSCHOOL_FORM_VERSION,
        ];
        if ($page_url !== '') {
            $source['page_url'] = $page_url;
        }
        if ($source['form_id'] < 1 || $source['entry_id'] < 1) {
            return ['ok' => false, 'code' => 'invalid_entry', 'body' => []];
        }

        $body = ['schema' => self::SCHEMA, 'source' => $source];
        $purpose = (string) ($settings['purpose'] ?? '');
        if (in_array($purpose, self::PURPOSES, true)) {
            $body['purpose'] = $purpose;
        }
        $body['contact'] = $contact;
        $size = self::text($values['school_size'] ?? '', 10);
        if (in_array($size, self::SCHOOL_SIZES, true)) {
            $body['school_size'] = $size;
        }
        $message = self::text($values['message'] ?? '', 4000, true);
        if ($message !== '') {
            $body['message'] = $message;
        }
        $body['consent'] = $consent;
        $body['submitted_at'] = $submitted;

        return ['ok' => true, 'code' => '', 'body' => $body];
    }

    public static function encode(array $body): string
    {
        return (string) wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Auswahlfelder können als Liste kommen: erste Auswahl. */
    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (! is_scalar($value)) {
            return '';
        }
        $value = $multiline ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);

        return mb_substr(trim($value), 0, $max);
    }

    private static function consent_given(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, static fn ($v): bool => trim((string) $v) !== '' && (string) $v !== '0') !== [];
        }

        return is_scalar($value) && trim((string) $value) !== '' && (string) $value !== '0' && $value !== false;
    }

    /** ISO 8601 → RFC 3339 in UTC ("2026-11-16T10:00:05Z"). */
    private static function utc(string $value): string
    {
        $time = trim($value) === '' ? false : strtotime($value);

        return $time === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
