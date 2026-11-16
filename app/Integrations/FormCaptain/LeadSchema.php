<?php

namespace App\Integrations\FormCaptain;

use DateTimeImmutable;

/**
 * Prüfung des Bodys gegen das JSON-Schema form-captain-lead/1 (Vertrag A.3,
 * docs/integrations/form-captain-leads.md). Bewusst ohne Schema-Bibliothek:
 * das Schema ist klein und fest versioniert. Unbekannte Eigenschaften werden
 * abgelehnt, nicht still verworfen. Fehler nennen JSON-Pointer und einen
 * Code, nie den Wert.
 */
final class LeadSchema
{
    public const ID = 'form-captain-lead/1';

    public const PURPOSES = ['demo', 'consultation', 'school_access_info'];

    public const SCHOOL_SIZES = ['1-2', '3-10', '11-50', '50+'];

    /** @var list<array{pointer: string, code: string}> */
    private array $errors = [];

    /**
     * @return list<array{pointer: string, code: string}> leer = gültig
     */
    public static function validate(mixed $data): array
    {
        $schema = new self;
        $schema->root($data);

        return $schema->errors;
    }

    private function root(mixed $data): void
    {
        if (! $this->isObject($data, '')) {
            return;
        }
        $this->properties($data, '', ['schema', 'source', 'purpose', 'contact', 'school_size', 'message', 'consent', 'submitted_at'], ['schema', 'source', 'contact', 'consent', 'submitted_at']);

        if (array_key_exists('schema', $data) && $data['schema'] !== self::ID) {
            $this->error('/schema', 'const');
        }
        if (array_key_exists('source', $data) && $this->isObject($data['source'], '/source')) {
            $source = $data['source'];
            $this->properties($source, '/source', ['site_url', 'page_url', 'form_id', 'entry_id', 'plugin_version'], ['site_url', 'form_id', 'entry_id']);
            $this->uri($source, '/source', 'site_url', 200);
            $this->uri($source, '/source', 'page_url', 500);
            $this->integer($source, '/source', 'form_id', 1);
            $this->integer($source, '/source', 'entry_id', 1);
            $this->string($source, '/source', 'plugin_version', 20);
        }
        $this->enum($data, '', 'purpose', self::PURPOSES);
        if (array_key_exists('contact', $data) && $this->isObject($data['contact'], '/contact')) {
            $contact = $data['contact'];
            $this->properties($contact, '/contact', ['name', 'email', 'phone', 'organisation', 'role'], ['email']);
            $this->string($contact, '/contact', 'name', 120);
            if ($this->string($contact, '/contact', 'email', 190) && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
                $this->error('/contact/email', 'format');
            }
            $this->string($contact, '/contact', 'phone', 40);
            $this->string($contact, '/contact', 'organisation', 160);
            $this->string($contact, '/contact', 'role', 80);
        }
        $this->enum($data, '', 'school_size', self::SCHOOL_SIZES);
        $this->string($data, '', 'message', 4000);
        if (array_key_exists('consent', $data) && $this->isObject($data['consent'], '/consent')) {
            $consent = $data['consent'];
            $this->properties($consent, '/consent', ['given_at', 'text_revision', 'text_hash'], ['given_at', 'text_revision']);
            $this->dateTime($consent, '/consent', 'given_at');
            $this->integer($consent, '/consent', 'text_revision', 1);
            if ($this->string($consent, '/consent', 'text_hash', 64) && preg_match('/^[a-f0-9]{64}$/', $consent['text_hash']) !== 1) {
                $this->error('/consent/text_hash', 'pattern');
            }
        }
        $this->dateTime($data, '', 'submitted_at');
    }

    /** Ein JSON-Objekt (nicht leer als Liste dekodiert). */
    private function isObject(mixed $value, string $pointer): bool
    {
        if (is_array($value) && ($value === [] || ! array_is_list($value))) {
            return true;
        }
        $this->error($pointer === '' ? '/' : $pointer, 'type');

        return false;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function properties(array $object, string $pointer, array $allowed, array $required): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->error($pointer.'/'.$this->escape((string) $key), 'additional_property');
            }
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $object)) {
                $this->error($pointer.'/'.$key, 'required');
            }
        }
    }

    /** @param array<string, mixed> $object */
    private function string(array $object, string $pointer, string $key, int $maxLength): bool
    {
        if (! array_key_exists($key, $object)) {
            return false;
        }
        if (! is_string($object[$key])) {
            $this->error($pointer.'/'.$key, 'type');

            return false;
        }
        if (! mb_check_encoding($object[$key], 'UTF-8') || mb_strlen($object[$key]) > $maxLength) {
            $this->error($pointer.'/'.$key, 'max_length');

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $object */
    private function uri(array $object, string $pointer, string $key, int $maxLength): void
    {
        if (! $this->string($object, $pointer, $key, $maxLength)) {
            return;
        }
        $scheme = strtolower((string) parse_url($object[$key], PHP_URL_SCHEME));
        if (filter_var($object[$key], FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            $this->error($pointer.'/'.$key, 'format');
        }
    }

    /** @param array<string, mixed> $object */
    private function integer(array $object, string $pointer, string $key, int $minimum): void
    {
        if (! array_key_exists($key, $object)) {
            return;
        }
        if (! is_int($object[$key])) {
            $this->error($pointer.'/'.$key, 'type');
        } elseif ($object[$key] < $minimum) {
            $this->error($pointer.'/'.$key, 'minimum');
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $values
     */
    private function enum(array $object, string $pointer, string $key, array $values): void
    {
        if (array_key_exists($key, $object) && ! in_array($object[$key], $values, true)) {
            $this->error($pointer.'/'.$key, 'enum');
        }
    }

    /** RFC 3339 date-time (JSON Schema "format": "date-time"). @param array<string, mixed> $object */
    private function dateTime(array $object, string $pointer, string $key): void
    {
        if (! $this->string($object, $pointer, $key, 40)) {
            return;
        }
        if (! self::parseDateTime($object[$key])) {
            $this->error($pointer.'/'.$key, 'format');
        }
    }

    public static function parseDateTime(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        // Kein Überlauf wie 2026-02-30 oder 24:00:00 (DateTime würde ihn still verschieben).
        return $date->format('Y-m-d\TH:i:s') === strtoupper(substr($value, 0, 19)) ? $date : null;
    }

    private function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], mb_substr($key, 0, 60));
    }

    private function error(string $pointer, string $code): void
    {
        $this->errors[] = ['pointer' => $pointer, 'code' => $code];
    }
}
