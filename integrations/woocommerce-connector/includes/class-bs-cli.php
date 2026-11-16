<?php

/**
 * WP-CLI:
 *
 * - wp bs-connector reconcile [--days=30]: vergleicht Positionen mit
 *   Bootsschul-Codes (zugestellt, fehlgeschlagen, storniert) der letzten Tage
 *   mit GET /orders/{id}/lines/{line} der Plattform und gibt Abweichungen aus.
 *   Ändert nichts.
 * - wp bs-connector resync <user-id|email>...: gleicht Kundenkonten
 *   ausdrücklich mit der Plattform ab (PATCH /accounts/{id}, ohne
 *   Prüfsummen-Vergleich).
 */
defined('ABSPATH') || exit;

final class BS_CLI
{
    /**
     * Zustellungen mit der Plattform abgleichen.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Bestellungen der letzten N Tage.
     * ---
     * default: 30
     * ---
     *
     * ## EXAMPLES
     *
     *     wp bs-connector reconcile --days=7
     *
     * @when after_wp_load
     */
    public function reconcile(array $args, array $assoc_args): void
    {
        if (! function_exists('wc_get_orders')) {
            WP_CLI::error('WooCommerce ist nicht aktiv.');
        }
        $days = max(1, (int) ($assoc_args['days'] ?? 30));
        $checked = 0;
        $rows = [];
        $page = 1;
        do {
            $order_ids = wc_get_orders(
                [
                    'type' => 'shop_order',
                    'date_created' => '>'.(time() - $days * DAY_IN_SECONDS),
                    'limit' => 100,
                    'paged' => $page,
                    'return' => 'ids',
                    'orderby' => 'ID',
                    'order' => 'ASC',
                ]
            );
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (! $order) {
                    continue;
                }
                foreach ($order->get_items() as $item_id => $item) {
                    $status = (string) $item->get_meta('_bs_delivery_status');
                    if (! in_array($status, ['delivered', 'failed', 'cancelled'], true)) {
                        continue;
                    }
                    $target = json_decode((string) $item->get_meta('_bs_target'), true) ?: [];
                    $response = BS_Api_Client::request('GET', '/orders/'.rawurlencode((string) $order_id).'/lines/'.rawurlencode((string) $item_id));
                    $problems = BS_Reconcile::compare(
                        [
                            'status' => $status,
                            'batch_id' => (string) $item->get_meta('_bs_batch_id'),
                            'quantity' => (int) ($target['quantity'] ?? $item->get_quantity()),
                            'order_cancelled' => $order->has_status(['cancelled', 'refunded']),
                        ],
                        $response['status'],
                        is_array($response['body']['data'] ?? null) ? $response['body']['data'] : null
                    );
                    $checked++;
                    foreach ($problems as $problem) {
                        $rows[] = [
                            'Bestellung' => $order->get_order_number(),
                            'Position' => $item_id,
                            'Shop' => $status,
                            'Plattform' => (string) ($response['body']['data']['status'] ?? ($response['status'] ?? '—')),
                            'Abweichung' => $problem,
                        ];
                    }
                }
            }
            $page++;
        } while (count($order_ids) === 100);

        if ($rows !== []) {
            WP_CLI\Utils\format_items('table', $rows, ['Bestellung', 'Position', 'Shop', 'Plattform', 'Abweichung']);
            BS_Log::warning('Abgleich mit Abweichungen', ['checked' => $checked, 'mismatches' => count($rows), 'days' => $days]);
            WP_CLI::warning(sprintf('%d Position(en) geprüft, %d Abweichung(en).', $checked, count($rows)));

            return;
        }
        WP_CLI::success(sprintf('%d Position(en) der letzten %d Tage geprüft, keine Abweichungen.', $checked, $days));
    }

    /**
     * Kundenkonten ausdrücklich mit der Plattform abgleichen (PATCH /accounts/{id}),
     * auch wenn sich die abgeglichenen Felder im Shop nicht geändert haben.
     *
     * Sendet sofort (nicht über Action Scheduler), damit das Ergebnis je Konto
     * hier erscheint. Nach einem Fehler plant das Plugin wie sonst bis zu zwei
     * Wiederholungen ein; nach dem letzten Fehlschlag geht eine Alarm-Mail.
     * Beendet sich mit Fehlerstatus, wenn ein Konto nicht abgeglichen wurde.
     *
     * ## OPTIONS
     *
     * <user>...
     * : Nutzer-ID oder E-Mail-Adresse des Kundenkontos.
     *
     * ## EXAMPLES
     *
     *     wp bs-connector resync 42 kunde@example.de
     *
     * @when after_wp_load
     */
    public function resync(array $args, array $assoc_args): void
    {
        if (! class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce ist nicht aktiv.');
        }
        $failed = 0;
        foreach ($args as $ref) {
            [$ok, $message] = self::resync_one((string) $ref);
            if ($ok) {
                WP_CLI::log($message);
            } else {
                $failed++;
                WP_CLI::warning($message);
            }
        }
        if ($failed > 0) {
            WP_CLI::error(sprintf('%d von %d Konto/Konten nicht abgeglichen.', $failed, count($args)));
        }
        WP_CLI::success(sprintf('%d Konto/Konten abgeglichen.', count($args)));
    }

    /**
     * Ein Konto abgleichen.
     *
     * @return array{0: bool, 1: string} Erfolg und Meldung
     */
    public static function resync_one(string $ref): array
    {
        $user = ctype_digit($ref) ? get_userdata((int) $ref) : (is_email($ref) ? get_user_by('email', $ref) : false);
        if (! $user) {
            return [false, sprintf('%s: kein Nutzer gefunden.', $ref)];
        }
        $label = sprintf('#%d (%s)', (int) $user->ID, (string) $user->user_email);
        if (! BS_Accounts::is_registered((int) $user->ID)) {
            return [false, sprintf('%s: nicht bei der Plattform registriert, übersprungen.', $label)];
        }
        $result = BS_Accounts::sync_account('update', (int) $user->ID, 1, true);
        BS_Log::info('Kontoabgleich per WP-CLI', ['customer_id' => (int) $user->ID, 'result' => $result['result'], 'http_status' => $result['status']]);
        switch ($result['result']) {
            case 'synced':
                return [true, sprintf('%s: abgeglichen (HTTP %d).', $label, (int) $result['status'])];
            case 'retry':
                return [false, sprintf('%s: fehlgeschlagen (%s), Wiederholung in 5 Minuten eingeplant.', $label, (string) $result['error'])];
            default:
                return [false, sprintf('%s: fehlgeschlagen (%s).', $label, (string) $result['error'])];
        }
    }
}
