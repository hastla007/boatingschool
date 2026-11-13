<?php

/**
 * Betrieb im WordPress-Backend:
 *
 * - WooCommerce → Bootsschul-Zustellungen: alle Positionen mit fehlgeschlagener
 *   Zustellung (_bs_delivery_status = failed), Sammelaktion „Erneut zustellen“.
 * - „Verbindung testen“ auf dem Einstellungs-Tab (ruft GET /catalog auf).
 */
defined('ABSPATH') || exit;

final class BS_Admin
{
    const PAGE = 'bs-connector-deliveries';

    const TEST_RESULT = 'bs_connector_test_result_';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_menu'], 60);
        add_action('admin_post_bs_connector_bulk_retry', [__CLASS__, 'handle_bulk_retry']);
        add_action('admin_post_bs_connector_test', [__CLASS__, 'handle_connection_test']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function add_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Bootsschul-Zustellungen', 'bs-connector'),
            __('Bootsschul-Zustellungen', 'bs-connector'),
            'edit_shop_orders',
            self::PAGE,
            [__CLASS__, 'render_failed_page']
        );
    }

    /**
     * Fehlgeschlagene Positionen (neueste zuerst). Die Positions-Metadaten
     * liegen bei klassischer und HPOS-Speicherung in denselben Tabellen.
     *
     * @return list<object{order_item_id: string, order_id: string, order_item_name: string}>
     */
    public static function failed_items(int $limit = 200): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oi.order_item_id, oi.order_id, oi.order_item_name
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
                WHERE m.meta_key = '_bs_delivery_status' AND m.meta_value = 'failed'
                ORDER BY oi.order_id DESC, oi.order_item_id ASC
                LIMIT %d",
                $limit
            )
        );
    }

    public static function render_failed_page(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        $items = self::failed_items();
        echo '<div class="wrap"><h1>'.esc_html__('Bootsschul-Zustellungen', 'bs-connector').'</h1>';
        echo '<p>'.esc_html__('Positionen, deren Codes nicht im Codebestand der Bootsschule angekommen sind. „Erneut zustellen“ verwendet denselben Lieferauftrag – die Plattform liefert je Position höchstens einmal.', 'bs-connector').'</p>';
        if ($items === []) {
            echo '<p><strong>'.esc_html__('Keine fehlgeschlagenen Zustellungen.', 'bs-connector').'</strong></p></div>';

            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="bs_connector_bulk_retry">';
        wp_nonce_field('bs_connector_bulk_retry');
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="bs-select-all" onclick="document.querySelectorAll(\'.bs-item\').forEach(function(c){c.checked=this.checked}.bind(this))"></td>';
        echo '<th>'.esc_html__('Bestellung', 'bs-connector').'</th><th>'.esc_html__('Position', 'bs-connector').'</th><th>'.esc_html__('Versuche', 'bs-connector').'</th><th>'.esc_html__('Fehler', 'bs-connector').'</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $row) {
            $order = wc_get_order((int) $row->order_id);
            if (! $order) {
                continue;
            }
            $item = $order->get_item((int) $row->order_item_id);
            $value = (int) $row->order_id.':'.(int) $row->order_item_id;
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" class="bs-item" name="items[]" value="'.esc_attr($value).'"></th>';
            echo '<td><a href="'.esc_url($order->get_edit_order_url()).'">#'.esc_html($order->get_order_number()).'</a><br><small>'.esc_html(wc_format_datetime($order->get_date_created())).'</small></td>';
            echo '<td>'.esc_html((string) $row->order_item_name).'</td>';
            echo '<td>'.esc_html((string) ($item ? (int) $item->get_meta('_bs_attempts') : 0)).'</td>';
            echo '<td>'.esc_html($item ? (string) $item->get_meta('_bs_error') : '').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">'.esc_html__('Erneut zustellen', 'bs-connector').'</button></p>';
        echo '</form></div>';
    }

    public static function handle_bulk_retry(): void
    {
        check_admin_referer('bs_connector_bulk_retry');
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        $count = 0;
        $values = isset($_POST['items']) && is_array($_POST['items']) ? array_map('sanitize_text_field', wp_unslash($_POST['items'])) : [];
        foreach (array_slice($values, 0, 200) as $value) {
            if (! preg_match('/^(\d+):(\d+)$/', (string) $value, $m)) {
                continue;
            }
            $count += BS_Orders::retry_item((int) $m[1], (int) $m[2]) ? 1 : 0;
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'bs_retried' => $count], admin_url('admin.php')));
        exit;
    }

    // ---------------------------------------------------------------- Verbindung testen

    public static function connection_test_url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=bs_connector_test'), 'bs_connector_test');
    }

    public static function handle_connection_test(): void
    {
        check_admin_referer('bs_connector_test');
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        // Frisch abfragen, nicht aus dem Zwischenspeicher.
        delete_transient('bs_connector_catalog');
        $response = BS_Api_Client::request('GET', '/catalog');
        if ($response['status'] === 200) {
            $data = (array) ($response['body']['data'] ?? []);
            $result = [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: 1: Anzahl Kurse, 2: Anzahl Produkte */
                    __('Verbindung zur Bootsschul-Plattform erfolgreich: %1$d Kurse und %2$d Produkte im Katalog.', 'bs-connector'),
                    count((array) ($data['courses'] ?? [])),
                    count((array) ($data['products'] ?? []))
                ),
            ];
        } elseif ($response['status'] === 401) {
            $result = ['type' => 'error', 'message' => __('Verbindung fehlgeschlagen: Die Plattform lehnt die Signatur ab. Bitte Schlüssel-ID und Geheimnis (Shop → App) sowie die Uhrzeit des Servers prüfen.', 'bs-connector')];
        } else {
            $result = [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: Fehlermeldung */
                    __('Verbindung fehlgeschlagen: %s', 'bs-connector'),
                    (string) ($response['error'] ?? ('HTTP '.$response['status']))
                ),
            ];
        }
        BS_Log::info('Verbindungstest', ['http_status' => $response['status'], 'ok' => $result['type'] === 'success']);
        set_transient(self::TEST_RESULT.get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=bs_connector'));
        exit;
    }

    public static function notices(): void
    {
        $result = get_transient(self::TEST_RESULT.get_current_user_id());
        if (is_array($result)) {
            delete_transient(self::TEST_RESULT.get_current_user_id());
            echo '<div class="notice notice-'.($result['type'] === 'success' ? 'success' : 'error').' is-dismissible"><p>'.esc_html((string) $result['message']).'</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
        if (isset($_GET['page'], $_GET['bs_retried']) && $_GET['page'] === self::PAGE) {
            $count = absint($_GET['bs_retried']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sprintf(
                /* translators: %d: Anzahl Positionen */
                _n('%d Position wird erneut zugestellt.', '%d Positionen werden erneut zugestellt.', $count, 'bs-connector'),
                $count
            )).'</p></div>';
        }
    }
}
