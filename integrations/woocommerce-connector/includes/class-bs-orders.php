<?php

/**
 * Zustellung bezahlter Bestellpositionen an die Plattform (genau ein
 * Code-Batch je Position) und Sperren unbenutzter Codes bei Rückerstattung
 * oder Storno. Läuft im Hintergrund über Action Scheduler.
 *
 * Positions-Metadaten: _bs_target (fester Lieferauftrag, beim Einplanen
 * eingefroren), _bs_delivery_status (pending|delivered|failed|cancelled),
 * _bs_attempts, _bs_error, _bs_batch_id.
 */
defined('ABSPATH') || exit;

final class BS_Orders
{
    const HOOK_DELIVER = 'bs_connector_deliver_item';

    const HOOK_VOID = 'bs_connector_void_item';

    const GROUP = 'bs-connector';

    public static function init(): void
    {
        add_action('woocommerce_payment_complete', [__CLASS__, 'schedule_order']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'schedule_order']);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'schedule_order']);
        add_action(self::HOOK_DELIVER, [__CLASS__, 'deliver_item'], 10, 2);

        add_action('woocommerce_order_refunded', [__CLASS__, 'on_refund'], 10, 2);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_cancel']);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'on_cancel']);
        add_action(self::HOOK_VOID, [__CLASS__, 'void_item'], 10, 4);

        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('admin_post_bs_connector_retry', [__CLASS__, 'handle_retry']);
        add_filter('woocommerce_hidden_order_itemmeta', [__CLASS__, 'hide_item_meta']);
    }

    // ---------------------------------------------------------------- Zustellung

    /** Bezahlte Bestellung: jede Position mit Bootsschul-Codes einplanen (einmalig). */
    public static function schedule_order($order_id): void
    {
        $order = wc_get_order($order_id);
        if (! $order || ! $order->is_paid()) {
            return;
        }
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (! BS_Products::is_platform_product($product) || (string) $item->get_meta('_bs_delivery_status') !== '') {
                continue;
            }
            $target = BS_Products::target($product);
            $item->update_meta_data('_bs_target', wp_json_encode($target));
            $item->update_meta_data('_bs_delivery_status', 'pending');
            $item->update_meta_data('_bs_attempts', 0);
            $item->save();
            self::enqueue_delivery((int) $order->get_id(), (int) $item_id, 0);
            $order->add_order_note(sprintf( /* translators: %s: Produktname */ __('Bootsschul-Codes für „%s“ zur Zustellung eingeplant.', 'bs-connector'), $item->get_name()));
        }
    }

    private static function enqueue_delivery(int $order_id, int $item_id, int $delay_minutes): void
    {
        $args = [$order_id, $item_id];
        if (! function_exists('as_schedule_single_action')) {
            self::deliver_item($order_id, $item_id);

            return;
        }
        if (as_has_scheduled_action(self::HOOK_DELIVER, $args, self::GROUP)) {
            return;
        }
        as_schedule_single_action(time() + $delay_minutes * MINUTE_IN_SECONDS, self::HOOK_DELIVER, $args, self::GROUP);
    }

    public static function deliver_item($order_id, $item_id): void
    {
        $order = wc_get_order((int) $order_id);
        $item = $order ? $order->get_item((int) $item_id) : null;
        if (! $order || ! $item || (string) $item->get_meta('_bs_delivery_status') !== 'pending') {
            return;
        }
        // Inzwischen storniert/erstattet: nichts mehr liefern.
        if (! $order->is_paid()) {
            self::set_item_status($item, 'cancelled', null);

            return;
        }

        $attempt = (int) $item->get_meta('_bs_attempts') + 1;
        $item->update_meta_data('_bs_attempts', $attempt);
        $item->save();

        // Ab dem zweiten Versuch zuerst nachsehen, ob der erste doch angekommen ist.
        $response = $attempt > 1
            ? BS_Api_Client::request('GET', '/orders/'.rawurlencode((string) $order->get_id()).'/lines/'.rawurlencode((string) $item_id))
            : ['status' => 404];
        if ($response['status'] !== 200) {
            $target = json_decode((string) $item->get_meta('_bs_target'), true) ?: BS_Products::target($item->get_product());
            $response = BS_Api_Client::request(
                'POST',
                '/orders/lines',
                [
                    'shop_customer_id' => (string) $order->get_customer_id(),
                    'order_id' => (string) $order->get_id(),
                    'order_line_id' => (string) $item_id,
                    'order_number' => (string) $order->get_order_number(),
                    'kind' => $target['kind'],
                    'course_code' => $target['course_code'],
                    'product_code' => $target['product_code'],
                    'option_product_codes' => $target['option_product_codes'],
                    'quantity' => (int) $item->get_quantity(),
                    'delivery' => 'platform',
                ],
                ['Idempotency-Key' => BS_Delivery_Policy::reference('woocommerce', (string) $order->get_id(), (string) $item_id)]
            );
        }

        [$result, $delay] = BS_Delivery_Policy::decide($response['status'], $attempt);
        if ($result === BS_Delivery_Policy::DELIVERED) {
            $item->update_meta_data('_bs_batch_id', (string) ($response['body']['data']['batch_id'] ?? ''));
            self::set_item_status($item, 'delivered', null);
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Anzahl, 2: Produktname */
                    __('%1$d Bootsschul-Code(s) für „%2$s“ im Codebestand der Bootsschule hinterlegt.', 'bs-connector'),
                    (int) ($response['body']['data']['quantity'] ?? $item->get_quantity()),
                    $item->get_name()
                )
            );

            return;
        }
        if ($result === BS_Delivery_Policy::RETRY) {
            $item->update_meta_data('_bs_error', (string) $response['error']);
            $item->save();
            self::enqueue_delivery((int) $order->get_id(), (int) $item_id, (int) $delay);

            return;
        }

        self::set_item_status($item, 'failed', (string) $response['error']);
        $order->add_order_note(sprintf( /* translators: 1: Produktname, 2: Fehler */ __('Zustellung der Bootsschul-Codes für „%1$s“ fehlgeschlagen: %2$s', 'bs-connector'), $item->get_name(), (string) $response['error']));
        self::alert(
            sprintf( /* translators: %s: Bestellnummer */ __('Bootsschul-Codes für Bestellung %s nicht zugestellt', 'bs-connector'), $order->get_order_number()),
            sprintf(
                "Position: %s\nVersuche: %d\nFehler: %s\n\nIn der Bestellung über „Bootsschul-Codes → Erneut zustellen“ neu anstoßen.\n%s",
                $item->get_name(),
                $attempt,
                (string) $response['error'],
                $order->get_edit_order_url()
            )
        );
    }

    private static function set_item_status(WC_Order_Item $item, string $status, ?string $error): void
    {
        $item->update_meta_data('_bs_delivery_status', $status);
        if ($error === null) {
            $item->delete_meta_data('_bs_error');
        } else {
            $item->update_meta_data('_bs_error', $error);
        }
        $item->save();
    }

    // ---------------------------------------------------------------- Rückerstattung / Storno

    public static function on_refund($order_id, $refund_id): void
    {
        $refund = wc_get_order((int) $refund_id);
        if (! $refund) {
            return;
        }
        foreach ($refund->get_items() as $refund_item) {
            $original_id = (int) $refund_item->get_meta('_refunded_item_id');
            $quantity = abs((int) $refund_item->get_quantity());
            if ($original_id && $quantity > 0) {
                self::enqueue_void((int) $order_id, $original_id, $quantity, 'refund-'.(int) $refund_id.'-'.$original_id);
            }
        }
    }

    public static function on_cancel($order_id): void
    {
        $order = wc_get_order((int) $order_id);
        if (! $order) {
            return;
        }
        foreach ($order->get_items() as $item_id => $item) {
            $status = (string) $item->get_meta('_bs_delivery_status');
            if ($status === 'pending') {
                // Noch nicht geliefert: Zustellung abbrechen. Kam ein laufender Versuch
                // doch an, sperrt die Nachfrage beim nächsten Lauf (cancelled) nichts --
                // deshalb zusätzlich ein Storno für den Fall der Lieferung.
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions(self::HOOK_DELIVER, [(int) $order_id, (int) $item_id], self::GROUP);
                }
                self::set_item_status($item, 'cancelled', null);
            }
            if (in_array($status, ['pending', 'delivered'], true)) {
                self::enqueue_void((int) $order_id, (int) $item_id, null, 'cancel-'.(int) $order_id.'-'.(int) $item_id);
            }
        }
    }

    private static function enqueue_void(int $order_id, int $item_id, ?int $quantity, string $key): void
    {
        $args = [$order_id, $item_id, $quantity, $key];
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_VOID, $args, self::GROUP);
        } else {
            self::void_item(...$args);
        }
    }

    public static function void_item($order_id, $item_id, $quantity, $key, $attempt = 1): void
    {
        $order = wc_get_order((int) $order_id);
        $item = $order ? $order->get_item((int) $item_id) : null;
        if (! $item) {
            return;
        }
        $batch_id = (string) $item->get_meta('_bs_batch_id');
        if ($batch_id === '') {
            // Batch-ID unbekannt (z. B. Zustellung lief beim Storno): über die Position nachfragen.
            $lookup = BS_Api_Client::request('GET', '/orders/'.rawurlencode((string) $order_id).'/lines/'.rawurlencode((string) $item_id));
            $batch_id = $lookup['status'] === 200 ? (string) ($lookup['body']['data']['batch_id'] ?? '') : '';
            if ($batch_id === '') {
                return; // Nie geliefert: nichts zu sperren.
            }
            $item->update_meta_data('_bs_batch_id', $batch_id);
            $item->save();
        }

        $response = BS_Api_Client::request(
            'POST',
            '/batches/'.rawurlencode($batch_id).'/void',
            array_filter(
                [
                    'reason' => sprintf('Bestellung %s: %s', $order->get_order_number(), strpos((string) $key, 'refund-') === 0 ? 'Rückerstattung' : 'Storno'),
                    'quantity' => $quantity === null ? null : (int) $quantity,
                ],
                static fn ($v) => $v !== null
            ),
            ['Idempotency-Key' => (string) $key]
        );

        if ($response['status'] === 200) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Anzahl, 2: eingelöst, 3: Produktname */
                    __('%1$d unbenutzte(r) Bootsschul-Code(s) für „%3$s“ gesperrt; %2$d bereits eingelöst (bleiben gültig).', 'bs-connector'),
                    (int) ($response['body']['data']['voided_now'] ?? 0),
                    (int) ($response['body']['data']['redeemed'] ?? 0),
                    $item->get_name()
                )
            );

            return;
        }
        [$result, $delay] = BS_Delivery_Policy::decide($response['status'], (int) $attempt);
        if ($result === BS_Delivery_Policy::RETRY && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + (int) $delay * MINUTE_IN_SECONDS, self::HOOK_VOID, [(int) $order_id, (int) $item_id, $quantity, (string) $key, (int) $attempt + 1], self::GROUP);

            return;
        }
        self::alert(
            sprintf( /* translators: %s: Bestellnummer */ __('Bootsschul-Codes für Bestellung %s nicht gesperrt', 'bs-connector'), $order->get_order_number()),
            sprintf("Position: %s\nFehler: %s\n\nBitte die Codes auf der Plattform prüfen.\n%s", $item->get_name(), (string) $response['error'], $order->get_edit_order_url())
        );
    }

    // ---------------------------------------------------------------- Verwaltung

    public static function alert(string $subject, string $message): void
    {
        $to = BS_Settings::get()['alert_email'];
        if ($to !== '') {
            wp_mail($to, '['.wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES).'] '.$subject, $message);
        }
    }

    public static function hide_item_meta(array $keys): array
    {
        return array_merge($keys, ['_bs_target', '_bs_delivery_status', '_bs_attempts', '_bs_error', '_bs_batch_id']);
    }

    public static function add_meta_box(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('bs-connector-delivery', __('Bootsschul-Codes', 'bs-connector'), [__CLASS__, 'render_meta_box'], $screen, 'side');
    }

    public static function render_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (! $order) {
            return;
        }
        $labels = [
            'pending' => __('wird zugestellt', 'bs-connector'),
            'delivered' => __('im Codebestand der Schule', 'bs-connector'),
            'failed' => __('fehlgeschlagen', 'bs-connector'),
            'cancelled' => __('abgebrochen', 'bs-connector'),
        ];
        $any = false;
        echo '<ul>';
        foreach ($order->get_items() as $item_id => $item) {
            $status = (string) $item->get_meta('_bs_delivery_status');
            if ($status === '') {
                continue;
            }
            $any = true;
            echo '<li><strong>'.esc_html($item->get_name()).'</strong>: '.esc_html($labels[$status] ?? $status);
            $error = (string) $item->get_meta('_bs_error');
            if ($error !== '' && $status !== 'delivered') {
                echo '<br><small>'.esc_html($error).'</small>';
            }
            if ($status === 'failed' && current_user_can('edit_shop_orders')) {
                $url = wp_nonce_url(admin_url('admin-post.php?action=bs_connector_retry&order_id='.$order->get_id().'&item_id='.$item_id), 'bs_connector_retry_'.$item_id);
                echo '<br><a class="button" href="'.esc_url($url).'">'.esc_html__('Erneut zustellen', 'bs-connector').'</a>';
            }
            echo '</li>';
        }
        echo '</ul>';
        if (! $any) {
            echo '<p>'.esc_html__('Keine Positionen mit Bootsschul-Codes.', 'bs-connector').'</p>';
        }
    }

    public static function handle_retry(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        check_admin_referer('bs_connector_retry_'.$item_id);
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : null;
        if ($item && (string) $item->get_meta('_bs_delivery_status') === 'failed') {
            // Gleicher Lieferauftrag, gleiche Referenz: die App liefert höchstens einmal.
            $item->update_meta_data('_bs_delivery_status', 'pending');
            $item->update_meta_data('_bs_attempts', 0);
            $item->save();
            self::enqueue_delivery($order_id, $item_id, 0);
            $order->add_order_note(__('Zustellung der Bootsschul-Codes erneut angestoßen.', 'bs-connector'));
        }
        wp_safe_redirect($order ? $order->get_edit_order_url() : admin_url());
        exit;
    }
}
