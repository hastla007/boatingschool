<?php

/**
 * WP-CLI: wp bs-connector reconcile [--days=30]
 *
 * Vergleicht Positionen mit Bootsschul-Codes (zugestellt, fehlgeschlagen,
 * storniert) der letzten Tage mit GET /orders/{id}/lines/{line} der Plattform
 * und gibt Abweichungen aus. Ändert nichts.
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
}
