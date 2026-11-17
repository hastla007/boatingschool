<?php

/**
 * Kleines Merkbuch des Connectors (BS-12): welche Entry-ID welche lead_id der Plattform bekommen hat, damit eine
 * Löschung in Form Captain den Lead in der Plattform löschen kann (Vertrag A.8). Keine Personendaten – nur IDs und
 * Zeitpunkt. Den Zustellstatus selbst (Versuche, letzter Fehler, Wiederholen) führt die Outbox von Form Captain.
 *
 * Offene Löschungen (Plattform nicht erreichbar) stehen in einer zweiten Option und werden stündlich wiederholt.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Ledger
{
    public const OPTION = 'boatingschool_form_ledger';

    public const PENDING_OPTION = 'boatingschool_form_pending_deletions';

    /** Einträge älter als das werden beim Schreiben entfernt (Aufbewahrung der Plattform + Puffer). */
    public const MAX_AGE = 400 * 86400;

    /** @return array<int,array{lead_id:string,form_id:int,at:int}> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    public static function remember(int $entry_id, int $form_id, string $lead_id): void
    {
        $now = time();
        $all = array_filter(self::all(), static fn ($row): bool => is_array($row) && (int) ($row['at'] ?? 0) > $now - self::MAX_AGE);
        $all[$entry_id] = ['lead_id' => $lead_id, 'form_id' => $form_id, 'at' => $now];
        update_option(self::OPTION, $all, false);
    }

    public static function lead_for(int $entry_id): string
    {
        return (string) (self::all()[$entry_id]['lead_id'] ?? '');
    }

    public static function forget(int $entry_id): void
    {
        $all = self::all();
        unset($all[$entry_id]);
        update_option(self::OPTION, $all, false);
    }

    /** @return array<string,int> lead_id => Anzahl Versuche */
    public static function pending(): array
    {
        $stored = get_option(self::PENDING_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string,int> $pending */
    public static function set_pending(array $pending): void
    {
        if ($pending === []) {
            delete_option(self::PENDING_OPTION);

            return;
        }
        update_option(self::PENDING_OPTION, $pending, false);
    }
}
