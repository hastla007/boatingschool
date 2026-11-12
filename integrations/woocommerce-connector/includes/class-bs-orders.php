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
        add_action(self::HOOK_VOID, [__CLASS__, 'void_item'], 10, 5);

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
            $status = (string) $item->get_meta('_bs_delivery_status');
            if ($status === 'cancelled' && ! $item->get_meta('_bs_reactivation_alerted')) {
                // Storniert und später wieder auf bezahlt gesetzt: nichts automatisch
                // nachliefern (Codes können bereits gesperrt sein) -- Mensch entscheidet.
                $item->update_meta_data('_bs_reactivation_alerted', 1);
                $item->save();
                $order->add_order_note(sprintf(__('Bootsschul-Codes für „%s“: Die Position war storniert. Bitte prüfen und bei Bedarf neu bestellen lassen.', 'bs-connector'), $item->get_name()));
                self::alert(
                    sprintf(__('Stornierte Bestellung %s wieder aktiv', 'bs-connector'), $order->get_order_number()),
                    sprintf("Position: %s\nDie Codes dieser Position wurden storniert bzw. gesperrt und werden nicht automatisch erneut geliefert.\n%s", $item->get_name(), $order->get_edit_order_url())
                );

                continue;
            }
            if (! in_array($status, ['', 'deferred'], true) || ($status === '' && ! BS_Products::is_platform_product($product))) {
                continue;
            }
            // Lieferauftrag beim ersten Einplanen einfrieren (Zuordnung, Menge,
            // Kunde) -- spätere Änderungen an Produkt oder Bestellung dürfen eine
            // schon angekommene Lieferung nicht in einen Konflikt verwandeln.
            if ((string) $item->get_meta('_bs_target') === '') {
                $target = BS_Products::target($product) + [
                    'quantity' => (int) $item->get_quantity(),
                    'shop_customer_id' => (string) $order->get_customer_id(),
                ];
                $item->update_meta_data('_bs_target', wp_json_encode($target));
            }
            $item->update_meta_data('_bs_delivery_status', 'pending');
            // Versuchszähler behalten: nach einer Zurückstellung fragt der nächste
            // Versuch zuerst nach, ob ein früherer angekommen ist.
            $item->update_meta_data('_bs_attempts', (int) $item->get_meta('_bs_attempts'));
            $item->save();
            self::enqueue_delivery((int) $order->get_id(), (int) $item_id, 0, true);
            $order->add_order_note(sprintf( /* translators: %s: Produktname */ __('Bootsschul-Codes für „%s“ zur Zustellung eingeplant.', 'bs-connector'), $item->get_name()));
        }
    }

    /**
     * @param  bool  $first  Erstes Einplanen: keine zweite Aktion, falls schon eine
     *                       wartet. Bei Wiederholungen nicht prüfen -- die gerade
     *                       laufende Aktion zählt dort selbst als „geplant“.
     */
    private static function enqueue_delivery(int $order_id, int $item_id, int $delay_minutes, bool $first): void
    {
        $args = [$order_id, $item_id];
        if (! function_exists('as_schedule_single_action')) {
            if ($delay_minutes === 0) {
                self::deliver_item($order_id, $item_id);
            }

            return;
        }
        if ($first && as_has_scheduled_action(self::HOOK_DELIVER, $args, self::GROUP)) {
            return;
        }
        as_schedule_single_action(time() + $delay_minutes * MINUTE_IN_SECONDS, self::HOOK_DELIVER, $args, self::GROUP);
    }

    public static function deliver_item($order_id, $item_id): void
    {
        $order = wc_get_order((int) $order_id);
        $item = $order ? $order->get_item((int) $item_id) : null;
        if (! $order || ! $item || (int) $item->get_order_id() !== (int) $order->get_id() || (string) $item->get_meta('_bs_delivery_status') !== 'pending') {
            return;
        }
        // Nicht (mehr) bezahlt, z. B. „In Wartestellung“: Zustellung zurückstellen.
        // Wird die Bestellung wieder „In Bearbeitung“, plant schedule_order neu ein;
        // wird sie storniert, sperrt on_cancel (über _bs_target) alles Gelieferte.
        if (! $order->is_paid()) {
            $item->update_meta_data('_bs_delivery_status', 'deferred');
            $item->save();

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
                    'shop_customer_id' => (string) ($target['shop_customer_id'] ?? $order->get_customer_id()),
                    'order_id' => (string) $order->get_id(),
                    'order_line_id' => (string) $item_id,
                    'order_number' => (string) $order->get_order_number(),
                    'kind' => $target['kind'],
                    'course_code' => $target['course_code'],
                    'product_code' => $target['product_code'],
                    'option_product_codes' => $target['option_product_codes'],
                    'quantity' => (int) ($target['quantity'] ?? $item->get_quantity()),
                    'delivery' => 'platform',
                ],
                ['Idempotency-Key' => BS_Delivery_Policy::reference('woocommerce', (string) $order->get_id(), (string) $item_id)]
            );
        }

        [$result, $delay] = BS_Delivery_Policy::decide($response['status'], $attempt);
        // Während des Aufrufs kann die Bestellung storniert oder erstattet worden
        // sein: den aktuellen Stand neu lesen, statt ihn zu überschreiben.
        $order = wc_get_order((int) $order_id) ?: $order;
        $item = $order->get_item((int) $item_id) ?: $item;
        // Nur ein echtes Storno zählt -- „In Wartestellung“ (Prüfung, Streitfall)
        // sperrt nichts, sonst blieben bezahlte Codes dauerhaft gesperrt.
        $cancelled_meanwhile = (string) $item->get_meta('_bs_delivery_status') === 'cancelled' || $order->has_status(['cancelled', 'refunded']);
        if ($result === BS_Delivery_Policy::DELIVERED) {
            $item->update_meta_data('_bs_batch_id', (string) ($response['body']['data']['batch_id'] ?? ''));
            self::set_item_status($item, 'delivered', null);
            if ($cancelled_meanwhile) {
                self::enqueue_void((int) $order_id, (int) $item_id, null, 'cancel-'.(int) $order_id.'-'.(int) $item_id);
            }
            self::flush_deferred_voids($item);
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
        if ($cancelled_meanwhile) {
            // Storniert, während der Aufruf lief: nicht weiter liefern. Kam der
            // Aufruf trotz fehlender Antwort an, sperrt der Storno-Lauf die Codes
            // (er fragt bei „nicht gefunden“ mehrmals nach).
            self::set_item_status($item, 'cancelled', null);
            self::enqueue_void((int) $order_id, (int) $item_id, null, 'cancel-'.(int) $order_id.'-'.(int) $item_id);

            return;
        }
        if ($result === BS_Delivery_Policy::RETRY) {
            $item->update_meta_data('_bs_error', (string) $response['error']);
            $item->save();
            self::enqueue_delivery((int) $order->get_id(), (int) $item_id, (int) $delay, false);

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
            $original = $original_id ? wc_get_order((int) $order_id)?->get_item($original_id) : null;
            if ($original && (string) $original->get_meta('_bs_target') !== '' && $quantity > 0) {
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
            if (in_array($status, ['pending', 'deferred'], true)) {
                // Noch nicht geliefert: Zustellung abbrechen. Kam ein laufender Versuch
                // doch an, sperrt die Nachfrage beim nächsten Lauf (cancelled) nichts --
                // deshalb zusätzlich ein Storno für den Fall der Lieferung.
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions(self::HOOK_DELIVER, [(int) $order_id, (int) $item_id], self::GROUP);
                }
                self::set_item_status($item, 'cancelled', null);
            }
            // Auch „fehlgeschlagen“ und zurückgestellte Positionen: ein letzter Versuch
            // kann angekommen sein, obwohl die Antwort ausblieb.
            if ((string) $item->get_meta('_bs_target') !== '') {
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
        if (! $item || (int) $item->get_order_id() !== (int) $order->get_id()) {
            return;
        }
        $batch_id = (string) $item->get_meta('_bs_batch_id');
        if ($batch_id === '') {
            // Batch-ID unbekannt (z. B. Zustellung lief beim Storno): über die Position nachfragen.
            $lookup = BS_Api_Client::request('GET', '/orders/'.rawurlencode((string) $order_id).'/lines/'.rawurlencode((string) $item_id));
            if ($lookup['status'] !== 200 && $lookup['status'] !== 404) {
                // Plattform nicht erreichbar o. Ä.: das heißt nicht „nichts geliefert“.
                self::retry_void_or_alert($order, $item, $quantity, (string) $key, (int) $attempt, $lookup);

                return;
            }
            $batch_id = $lookup['status'] === 200 ? (string) ($lookup['body']['data']['batch_id'] ?? '') : '';
            if ($batch_id === '') {
                $status = (string) $item->get_meta('_bs_delivery_status');
                if (in_array($status, ['pending', 'failed', 'deferred'], true) && $order->is_paid()) {
                    // Zustellung steht noch aus: Sperre merken und nach der Lieferung
                    // anwenden (geliefert wird immer die volle Menge der Position).
                    $deferred = (array) json_decode((string) $item->get_meta('_bs_deferred_voids'), true);
                    $deferred[(string) $key] = $quantity;
                    $item->update_meta_data('_bs_deferred_voids', wp_json_encode($deferred));
                    $item->save();
                    // Lief die Zustellung gerade fertig, die Sperre jetzt anwenden.
                    $fresh = wc_get_order((int) $order_id)?->get_item((int) $item_id);
                    if ($fresh && (string) $fresh->get_meta('_bs_delivery_status') === 'delivered') {
                        self::flush_deferred_voids($fresh);
                    }
                } elseif ((int) $item->get_meta('_bs_attempts') > 0 && (int) $attempt <= count(BS_Delivery_Policy::BACKOFF_MINUTES)) {
                    // Storniert, aber ein Lieferauftrag wurde schon gesendet: er kann
                    // noch verarbeitet werden -- später erneut nachsehen.
                    as_schedule_single_action(time() + BS_Delivery_Policy::BACKOFF_MINUTES[(int) $attempt - 1] * MINUTE_IN_SECONDS, self::HOOK_VOID,
                        [(int) $order_id, (int) $item_id, $quantity, (string) $key, (int) $attempt + 1], self::GROUP);
                }

                return; // Nichts geliefert: (noch) nichts zu sperren.
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
        self::retry_void_or_alert($order, $item, $quantity, (string) $key, (int) $attempt, $response);
    }

    private static function retry_void_or_alert(WC_Order $order, WC_Order_Item $item, $quantity, string $key, int $attempt, array $response): void
    {
        [$result, $delay] = BS_Delivery_Policy::decide($response['status'], $attempt);
        if ($result === BS_Delivery_Policy::RETRY && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + (int) $delay * MINUTE_IN_SECONDS, self::HOOK_VOID, [(int) $order->get_id(), (int) $item->get_id(), $quantity, $key, $attempt + 1], self::GROUP);

            return;
        }
        self::alert(
            sprintf( /* translators: %s: Bestellnummer */ __('Bootsschul-Codes für Bestellung %s nicht gesperrt', 'bs-connector'), $order->get_order_number()),
            sprintf("Position: %s\nFehler: %s\n\nBitte die Codes auf der Plattform prüfen.\n%s", $item->get_name(), (string) $response['error'], $order->get_edit_order_url())
        );
    }

    /** Vor der Lieferung eingegangene Rückerstattungen/Stornos jetzt anwenden. */
    private static function flush_deferred_voids(WC_Order_Item $item): void
    {
        $deferred = (array) json_decode((string) $item->get_meta('_bs_deferred_voids'), true);
        if ($deferred === []) {
            return;
        }
        $item->delete_meta_data('_bs_deferred_voids');
        $item->save();
        foreach ($deferred as $key => $quantity) {
            self::enqueue_void((int) $item->get_order_id(), (int) $item->get_id(), $quantity === null ? null : (int) $quantity, (string) $key);
        }
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
        return array_merge($keys, ['_bs_target', '_bs_delivery_status', '_bs_attempts', '_bs_error', '_bs_batch_id', '_bs_deferred_voids', '_bs_reactivation_alerted']);
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
                $url = wp_nonce_url(admin_url('admin-post.php?action=bs_connector_retry&order_id='.$order->get_id().'&item_id='.$item_id), 'bs_connector_retry_'.$order->get_id().'_'.$item_id);
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
        check_admin_referer('bs_connector_retry_'.$order_id.'_'.$item_id);
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : null;
        if ($item && (int) $item->get_order_id() === (int) $order->get_id() && (string) $item->get_meta('_bs_delivery_status') === 'failed') {
            // Gleicher Lieferauftrag, gleiche Referenz: die App liefert höchstens einmal.
            $item->update_meta_data('_bs_delivery_status', 'pending');
            $item->update_meta_data('_bs_attempts', 0);
            $item->save();
            self::enqueue_delivery($order_id, $item_id, 0, true);
            $order->add_order_note(__('Zustellung der Bootsschul-Codes erneut angestoßen.', 'bs-connector'));
        }
        wp_safe_redirect($order ? $order->get_edit_order_url() : admin_url());
        exit;
    }
}
