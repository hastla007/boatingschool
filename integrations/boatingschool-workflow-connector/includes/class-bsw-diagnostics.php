<?php

/**
 * Fehlerdiagnose (WF-CUST1-08): Zähler und letzte Fehler als Codes, nie mit
 * Werten aus Ereignissen oder Antworten. Liegt in einer nicht automatisch
 * geladenen Option; die Statusseite zeigt sie an.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Diagnostics
{
    const OPTION = 'bsw_connector_diagnostics';

    const MAX_CODES = 30;

    public static function get(): array
    {
        $d = get_option(self::OPTION, []);

        return (is_array($d) ? $d : []) + [
            'received' => 0, 'captured' => 0, 'duplicates' => 0, 'refused' => [], 'last_event_at' => null,
            'last_error' => null, 'handshake' => null, 'commands' => 0, 'command_errors' => [], 'last_command_error' => null,
            'registration' => null,
        ];
    }

    /** Ergebnis eines eingehenden Ereignisses: captured, duplicate oder ein Ablehnungscode. */
    public static function event(string $result, int $now): void
    {
        $d = self::get();
        $d['received']++;
        $d['last_event_at'] = $now;
        if ($result === 'captured') {
            $d['captured']++;
        } elseif ($result === 'duplicate') {
            $d['duplicates']++;
        } else {
            $d['refused'] = self::count($d['refused'], $result);
            $d['last_error'] = ['code' => self::code($result), 'at' => $now];
        }
        self::save($d);
    }

    public static function handshake(bool $ok, string $code, ?string $contract, int $now): void
    {
        $d = self::get();
        $d['handshake'] = ['ok' => $ok, 'code' => self::code($code), 'contract_version' => $contract, 'at' => $now];
        self::save($d);
    }

    public static function command(bool $ok, string $code, int $now): void
    {
        $d = self::get();
        $d['commands']++;
        if (! $ok) {
            $d['command_errors'] = self::count($d['command_errors'], $code);
            $d['last_command_error'] = ['code' => self::code($code), 'at' => $now];
        }
        self::save($d);
    }

    public static function registration(string $state): void
    {
        $d = self::get();
        if ($d['registration'] !== $state) {
            $d['registration'] = self::code($state);
            self::save($d);
        }
    }

    public static function reset(): void
    {
        delete_option(self::OPTION);
    }

    private static function count(array $codes, string $code): array
    {
        $code = self::code($code);
        $codes[$code] = (int) ($codes[$code] ?? 0) + 1;
        arsort($codes);

        return array_slice($codes, 0, self::MAX_CODES, true);
    }

    /** Nur kurze Codes aus Kleinbuchstaben, Ziffern und : _ . - */
    private static function code(string $code): string
    {
        return substr(preg_replace('/[^a-z0-9:_.-]/', '', strtolower($code)), 0, 80);
    }

    private static function save(array $d): void
    {
        update_option(self::OPTION, $d, false);
    }
}
