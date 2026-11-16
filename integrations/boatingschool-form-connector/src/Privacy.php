<?php

/**
 * Löschung und Widerruf (Vertrag form-captain-lead/1, A.8; docs/CONNECTORS.md 8 von Form Captain).
 *
 * - `form_captain_entry_deleted_v1`: der Lead in der Plattform wird per signiertem DELETE gelöscht (204 oder 404 =
 *   erledigt), sofern die Löschung nicht abgeschaltet ist (BOATINGSCHOOL_FORM_REMOTE_DELETE bzw. Einstellung).
 * - `form_captain_consent_withdrawn_v1`: Grundlage des Leads ist die Einwilligung – der Lead wird ebenso gelöscht.
 *
 * Ist die Plattform nicht erreichbar, bleibt die Löschung offen und wird stündlich wiederholt (höchstens 48 Versuche).
 * Läuft auch ohne gültige Lizenz (Datenschutzpflicht, kein Premium-Pfad), aber nur für Leads, die der Connector
 * früher zugestellt hat (Ledger). Eine Löschung in der Plattform wirkt nie auf den Form-Captain-Entry zurück.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Privacy
{
    public const CRON_HOOK = 'boatingschool_form_retry_deletions';

    public const MAX_ATTEMPTS = 48;

    public static function register(): void
    {
        if (! has_action('form_captain_entry_deleted_v1', [self::class, 'entry_deleted'])) {
            add_action('form_captain_entry_deleted_v1', [self::class, 'entry_deleted'], 10, 3);
            add_action('form_captain_consent_withdrawn_v1', [self::class, 'consent_withdrawn'], 10, 3);
            add_action(self::CRON_HOOK, [self::class, 'retry_pending']);
        }
    }

    public static function entry_deleted(int $entry_id, int $form_id = 0, string $reason = ''): void
    {
        unset($form_id, $reason);
        self::delete_for_entry($entry_id);
    }

    public static function consent_withdrawn(int $entry_id, int $form_id = 0, string $source = ''): void
    {
        unset($form_id, $source);
        self::delete_for_entry($entry_id);
    }

    private static function delete_for_entry(int $entry_id): void
    {
        $lead_id = Ledger::lead_for($entry_id);
        if ($lead_id === '') {
            return;
        }
        Ledger::forget($entry_id);
        if (! Settings::remote_delete()) {
            return;
        }
        if (! self::delete_remote($lead_id)) {
            $pending = Ledger::pending();
            $pending[$lead_id] = 1;
            Ledger::set_pending($pending);
            self::schedule();
        }
    }

    /** WP-Cron: offene Löschungen erneut senden. */
    public static function retry_pending(): void
    {
        $pending = Ledger::pending();
        foreach ($pending as $lead_id => $attempts) {
            if (self::delete_remote((string) $lead_id) || (int) $attempts + 1 >= self::MAX_ATTEMPTS) {
                unset($pending[$lead_id]);
            } else {
                $pending[$lead_id] = (int) $attempts + 1;
            }
        }
        Ledger::set_pending($pending);
        if ($pending !== []) {
            self::schedule();
        }
    }

    /** true = in der Plattform gelöscht oder nicht (mehr) vorhanden. */
    private static function delete_remote(string $lead_id): bool
    {
        $response = Client::delete_lead($lead_id);
        if ($response['error'] === 'invalid_lead_id') {
            return true;
        }

        return in_array($response['status'], [204, 404], true);
    }

    private static function schedule(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 3600, self::CRON_HOOK);
        }
    }
}
