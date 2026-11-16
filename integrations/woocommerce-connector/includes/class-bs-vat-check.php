<?php

/**
 * USt-IdNr.-Prüfung über VIES (EU-Kommission) bei der Registrierung, an der
 * Kasse und beim Ändern der Rechnungsadresse.
 *
 * - Falsches Format oder laut VIES ungültig: Fehlermeldung, nichts wird angelegt.
 * - VIES bzw. der Dienst des Mitgliedstaats nicht erreichbar: angenommen,
 *   als „nicht geprüft“ markiert und über Action Scheduler später erneut
 *   geprüft (15 Minuten, 1, 4, 12 und 24 Stunden).
 *
 * Ergebnis am Kunden (Nutzer-Metadaten): _bs_vat_status, _bs_vat_number,
 * _bs_vat_checked_at, _bs_vat_request_id, _bs_vat_error, _bs_vat_attempts.
 * Sichtbar im Benutzerprofil (Backend) und als Bestellnotiz. Reine Logik:
 * BS_Vat.
 */
defined('ABSPATH') || exit;

final class BS_Vat_Check
{
    const HOOK_RECHECK = 'bs_connector_vat_recheck';

    /** Ergebnisse dieses Seitenaufrufs (USt-IdNr. => Ergebnis), damit VIES nur einmal gefragt wird. */
    private static array $results = [];

    public static function init(): void
    {
        if (! self::enabled()) {
            return;
        }
        add_filter('woocommerce_registration_errors', [__CLASS__, 'validate_registration'], 20, 3);
        // Vor BS_Accounts::on_created_customer (das die USt-IdNr. speichert).
        add_action('woocommerce_created_customer', [__CLASS__, 'on_created_customer'], 5);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout'], 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'validate_block_checkout'], 20);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_created'], 20);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'on_order_created'], 20);
        add_action('woocommerce_after_save_address_validation', [__CLASS__, 'validate_address'], 20, 2);
        add_action('added_user_meta', [__CLASS__, 'on_user_meta_changed'], 10, 4);
        add_action('updated_user_meta', [__CLASS__, 'on_user_meta_changed'], 10, 4);
        add_action(self::HOOK_RECHECK, [__CLASS__, 'recheck'], 10, 3);
        add_action('show_user_profile', [__CLASS__, 'render_profile']);
        add_action('edit_user_profile', [__CLASS__, 'render_profile']);
        add_action('admin_post_bs_connector_vat_recheck', [__CLASS__, 'handle_admin_recheck']);
    }

    /** Einstellung „USt-IdNr. per VIES prüfen“ bzw. Filter bs_connector_vat_check. */
    public static function enabled(): bool
    {
        return (bool) apply_filters('bs_connector_vat_check', BS_Settings::get()['vat_check'] !== 'no');
    }

    // ---------------------------------------------------------------- Prüfung

    /**
     * Prüft eine Nummer (Format, dann VIES). Ergebnis wie BS_Vat::map_response
     * plus vat_id, country und checked_at; null bei leerer Eingabe.
     */
    public static function check(string $vat_id, string $billing_country, bool $use_cache = true): ?array
    {
        $pre = BS_Vat::precheck($vat_id, $billing_country);
        if ($pre === null) {
            return null;
        }
        if ($use_cache && isset(self::$results[$pre['vat_id']])) {
            return self::$results[$pre['vat_id']];
        }
        $result = [
            'vat_id' => $pre['vat_id'],
            'country' => $pre['country'],
            'checked_at' => time(),
            'retry' => false,
            'error' => $pre['error'],
            'request_identifier' => '',
            'request_date' => '',
            'name' => '',
        ];
        if ($pre['status'] !== null) {
            $result['status'] = $pre['status'];
        } else {
            $result = array_merge($result, self::ask_vies($pre['country'], $pre['number']));
        }
        BS_Log::info('USt-IdNr. geprüft', ['vat_status' => $result['status'], 'country' => $result['country'], 'vies_error' => (string) $result['error'], 'request_identifier' => $result['request_identifier']]);

        return self::$results[$pre['vat_id']] = $result;
    }

    private static function ask_vies(string $country, string $number): array
    {
        $response = wp_remote_post(
            (string) apply_filters('bs_connector_vies_url', BS_Vat::ENDPOINT),
            [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => wp_json_encode(BS_Vat::request_body($country, $number, BS_Settings::get()['vies_requester'])),
                'timeout' => (int) apply_filters('bs_connector_vies_timeout', 10),
            ]
        );
        if (is_wp_error($response)) {
            BS_Log::warning('VIES nicht erreichbar', ['error' => $response->get_error_message()]);

            return BS_Vat::map_response(null, null);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return BS_Vat::map_response((int) wp_remote_retrieve_response_code($response), is_array($body) ? $body : null);
    }

    /** Fehlermeldung für Registrierung/Kasse, wenn die Nummer abgelehnt wird; sonst null. */
    private static function rejection(?array $result, string $entered): ?string
    {
        if ($result === null || $result['status'] !== BS_Vat::INVALID) {
            return null;
        }
        if ($result['error'] === 'FORMAT') {
            return sprintf(
                /* translators: 1: eingegebene USt-IdNr., 2: Ländercode */
                __('Die USt-IdNr. „%1$s“ hat nicht das gültige Format für %2$s. Bitte prüfen Sie die Eingabe (mit oder ohne Länderkennung, z. B. DE123456789).', 'bs-connector'),
                $entered,
                $result['country']
            );
        }

        return sprintf(
            /* translators: %s: eingegebene USt-IdNr. */
            __('Die USt-IdNr. „%s“ ist laut EU-Prüfdienst (VIES) nicht gültig. Bitte prüfen Sie die Eingabe.', 'bs-connector'),
            $entered
        );
    }

    // ---------------------------------------------------------------- Registrierung

    public static function validate_registration(WP_Error $errors, $username, $email): WP_Error
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce des Formulars.
        if (! isset($_POST['register']) || empty($_POST['bs_vat_id']) || $errors->has_errors()) {
            return $errors;
        }
        $entered = sanitize_text_field(wp_unslash($_POST['bs_vat_id']));
        $country = sanitize_text_field(wp_unslash($_POST['billing_country'] ?? ''));
        // phpcs:enable
        $message = self::rejection(self::check($entered, $country), $entered);
        if ($message !== null) {
            $errors->add('bs_vat_id', $message);
        }

        return $errors;
    }

    public static function on_created_customer(int $customer_id): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (empty($_POST['bs_vat_id'])) {
            return;
        }
        $result = self::check(sanitize_text_field(wp_unslash($_POST['bs_vat_id'])), sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')));
        // phpcs:enable
        if ($result !== null) {
            self::store_user($customer_id, $result, 1);
        }
    }

    // ---------------------------------------------------------------- Rechnungsadresse / Profil

    /** „Mein Konto → Adressen“: ungültige Nummer nicht speichern. */
    public static function validate_address($user_id, $address_type): void
    {
        $key = BS_Settings::get()['vat_meta_key'];
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce des Formulars.
        if ($address_type !== 'billing' || empty($_POST[$key])) {
            return;
        }
        $entered = sanitize_text_field(wp_unslash($_POST[$key]));
        $country = sanitize_text_field(wp_unslash($_POST['billing_country'] ?? get_user_meta((int) $user_id, 'billing_country', true)));
        // phpcs:enable
        $pre = BS_Vat::precheck($entered, $country);
        if ($pre === null || ($pre['vat_id'] === (string) get_user_meta((int) $user_id, '_bs_vat_number', true) && get_user_meta((int) $user_id, '_bs_vat_status', true) !== BS_Vat::INVALID)) {
            return; // Unverändert.
        }
        $message = self::rejection(self::check($entered, $country), $entered);
        if ($message !== null) {
            wc_add_notice($message, 'error');
        }
    }

    /**
     * Jede Änderung der USt-IdNr. am Kunden (Kasse, Adresse, Backend, andere
     * Plugins): Ergebnis dieses Aufrufs übernehmen oder im Hintergrund prüfen.
     */
    public static function on_user_meta_changed($meta_id, $user_id, $meta_key, $value): void
    {
        if ($meta_key !== BS_Settings::get()['vat_meta_key']) {
            return;
        }
        $user_id = (int) $user_id;
        $pre = BS_Vat::precheck((string) $value, (string) get_user_meta($user_id, 'billing_country', true));
        if ($pre === null) {
            self::clear_user($user_id);

            return;
        }
        if ($pre['vat_id'] === (string) get_user_meta($user_id, '_bs_vat_number', true) && (string) get_user_meta($user_id, '_bs_vat_status', true) !== '') {
            return; // Schon geprüft (oder Prüfung eingeplant).
        }
        if (isset(self::$results[$pre['vat_id']])) {
            self::store_user($user_id, self::$results[$pre['vat_id']], 1);

            return;
        }
        // Nicht im Seitenaufruf blockieren: im Hintergrund prüfen.
        update_user_meta($user_id, '_bs_vat_number', $pre['vat_id']);
        update_user_meta($user_id, '_bs_vat_status', BS_Vat::UNCHECKED);
        update_user_meta($user_id, '_bs_vat_attempts', 0);
        self::schedule('user', $user_id, 1, 0);
    }

    /** Ergebnis am Kunden speichern; „nicht geprüft“ plant die nächste Prüfung ein. */
    private static function store_user(int $user_id, array $result, int $attempt): void
    {
        update_user_meta($user_id, '_bs_vat_number', $result['vat_id']);
        update_user_meta($user_id, '_bs_vat_status', $result['status']);
        update_user_meta($user_id, '_bs_vat_checked_at', (int) $result['checked_at']);
        update_user_meta($user_id, '_bs_vat_request_id', (string) $result['request_identifier']);
        update_user_meta($user_id, '_bs_vat_error', (string) $result['error']);
        update_user_meta($user_id, '_bs_vat_attempts', $attempt);
        if ($result['status'] === BS_Vat::UNCHECKED && $result['retry']) {
            self::schedule_retry('user', $user_id, $attempt);
        }
    }

    private static function clear_user(int $user_id): void
    {
        foreach (['_bs_vat_number', '_bs_vat_status', '_bs_vat_checked_at', '_bs_vat_request_id', '_bs_vat_error', '_bs_vat_attempts'] as $key) {
            delete_user_meta($user_id, $key);
        }
    }

    // ---------------------------------------------------------------- Kasse

    /** USt-IdNr. aus dem Kassenformular (Feldname = Meta-Feld der Einstellungen). */
    private static function posted_vat(array $data): string
    {
        $key = BS_Settings::get()['vat_meta_key'];
        $value = $data[$key] ?? $data[ltrim($key, '_')] ?? $_POST[$key] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return sanitize_text_field(wp_unslash((string) $value));
    }

    /** Ergebnis für die Kasse: gespeichertes Ergebnis des Kunden (bis 24 Stunden alt) oder neu prüfen. */
    private static function checkout_result(string $entered, string $country): ?array
    {
        $pre = BS_Vat::precheck($entered, $country);
        if ($pre === null) {
            return null;
        }
        $user_id = get_current_user_id();
        if ($user_id && $pre['vat_id'] === (string) get_user_meta($user_id, '_bs_vat_number', true)
            && in_array((string) get_user_meta($user_id, '_bs_vat_status', true), [BS_Vat::VALID, BS_Vat::NOT_APPLICABLE], true)
            && time() - (int) get_user_meta($user_id, '_bs_vat_checked_at', true) < DAY_IN_SECONDS) {
            return self::$results[$pre['vat_id']] = self::user_result($user_id);
        }

        return self::check($entered, $country);
    }

    /** Gespeichertes Ergebnis eines Kunden im Format von check(). */
    private static function user_result(int $user_id): ?array
    {
        $status = (string) get_user_meta($user_id, '_bs_vat_status', true);
        if ($status === '') {
            return null;
        }
        $number = (string) get_user_meta($user_id, '_bs_vat_number', true);

        return [
            'vat_id' => $number,
            'country' => substr($number, 0, 2),
            'checked_at' => (int) get_user_meta($user_id, '_bs_vat_checked_at', true),
            'status' => $status,
            'retry' => $status === BS_Vat::UNCHECKED,
            'error' => (string) get_user_meta($user_id, '_bs_vat_error', true) ?: null,
            'request_identifier' => (string) get_user_meta($user_id, '_bs_vat_request_id', true),
            'request_date' => '',
            'name' => '',
        ];
    }

    public static function validate_checkout($data, WP_Error $errors): void
    {
        $entered = self::posted_vat((array) $data);
        if ($entered === '') {
            return;
        }
        $message = self::rejection(self::checkout_result($entered, (string) ($data['billing_country'] ?? '')), $entered);
        if ($message !== null) {
            $errors->add('bs_vat_id', $message);
        }
    }

    /** Block-Checkout: die Nummer steht dort schon an der Bestellung. */
    public static function validate_block_checkout($order): void
    {
        if (! $order instanceof WC_Order) {
            return;
        }
        $entered = self::order_vat($order);
        if ($entered === '') {
            return;
        }
        $message = self::rejection(self::checkout_result($entered, (string) $order->get_billing_country()), $entered);
        if ($message === null) {
            return;
        }
        $exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        if (class_exists($exception)) {
            throw new $exception('bs_vat_invalid', $message, 400);
        }
        throw new Exception($message);
    }

    private static function order_vat(WC_Order $order): string
    {
        $key = BS_Settings::get()['vat_meta_key'];
        foreach ([$key, '_'.ltrim($key, '_'), ltrim($key, '_')] as $candidate) {
            $value = (string) $order->get_meta($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** Neue Bestellung: Prüfergebnis als Bestellnotiz (und am Kunden speichern). */
    public static function on_order_created($order_or_id): void
    {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order((int) $order_or_id);
        if (! $order) {
            return;
        }
        $customer_id = (int) $order->get_customer_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce hat die Kasse geprüft.
        $entered = self::order_vat($order) ?: self::posted_vat($_POST);
        $result = null;
        if ($entered !== '') {
            $result = self::checkout_result($entered, (string) $order->get_billing_country());
            if ($result !== null && $customer_id && $result['vat_id'] !== (string) get_user_meta($customer_id, '_bs_vat_number', true)) {
                self::store_user($customer_id, $result, 1);
            }
        } elseif ($customer_id) {
            $result = self::user_result($customer_id);
        }
        if ($result === null) {
            return;
        }
        $order->update_meta_data('_bs_vat_number', $result['vat_id']);
        $order->update_meta_data('_bs_vat_status', $result['status']);
        $order->save();
        $order->add_order_note(self::note($result));
        // Gastbestellung, VIES nicht erreichbar: an der Bestellung erneut prüfen.
        if (! $customer_id && $result['status'] === BS_Vat::UNCHECKED && $result['retry']) {
            self::schedule_retry('order', (int) $order->get_id(), 1);
        }
    }

    // ---------------------------------------------------------------- Wiederholung

    private static function schedule_retry(string $type, int $id, int $attempt): void
    {
        $delay = BS_Vat::retry_delay($attempt);
        if ($delay === null) {
            BS_Log::error('USt-IdNr. konnte nicht geprüft werden (VIES dauerhaft nicht erreichbar)', ['type' => $type, 'id' => $id, 'attempt' => $attempt]);
            BS_Orders::alert(
                __('USt-IdNr. konnte nicht geprüft werden', 'bs-connector'),
                sprintf("%s #%d: Der EU-Prüfdienst VIES war bei %d Versuchen nicht erreichbar. Die USt-IdNr. ist als „nicht geprüft“ markiert. Bitte manuell prüfen (Benutzerprofil → „Jetzt erneut prüfen“).\n", $type === 'user' ? 'Kunde' : 'Bestellung', $id, $attempt)
            );

            return;
        }
        self::schedule($type, $id, $attempt + 1, $delay);
    }

    private static function schedule(string $type, int $id, int $attempt, int $delay_minutes): void
    {
        if (! function_exists('as_schedule_single_action')) {
            if ($delay_minutes === 0) {
                self::recheck($type, $id, $attempt);
            }

            return;
        }
        $args = [$type, $id, $attempt];
        if (! as_has_scheduled_action(self::HOOK_RECHECK, $args, BS_Orders::GROUP)) {
            as_schedule_single_action(time() + $delay_minutes * MINUTE_IN_SECONDS, self::HOOK_RECHECK, $args, BS_Orders::GROUP);
        }
    }

    /** Hintergrundprüfung (Action Scheduler) eines Kunden oder einer Gastbestellung. */
    public static function recheck($type, $id, $attempt = 1): void
    {
        $id = (int) $id;
        $attempt = max(1, (int) $attempt);
        if ($type === 'order') {
            self::recheck_order($id, $attempt);

            return;
        }
        $user = get_userdata($id);
        if (! $user) {
            return;
        }
        $entered = (string) get_user_meta($id, BS_Settings::get()['vat_meta_key'], true);
        $previous = (string) get_user_meta($id, '_bs_vat_status', true);
        // Wiederholung einer inzwischen erledigten Prüfung (z. B. von Hand neu geprüft).
        if ($attempt > 1 && $previous !== BS_Vat::UNCHECKED) {
            return;
        }
        $result = self::check($entered, (string) get_user_meta($id, 'billing_country', true), false);
        if ($result === null) {
            self::clear_user($id);

            return;
        }
        self::store_user($id, $result, $attempt);
        if ($result['status'] === BS_Vat::UNCHECKED) {
            return;
        }
        // Bestellungen, die mit „nicht geprüft“ angelegt wurden, erhalten das Ergebnis als Notiz.
        foreach (wc_get_orders(['customer_id' => $id, 'limit' => 20, 'meta_key' => '_bs_vat_status', 'meta_value' => BS_Vat::UNCHECKED]) as $order) { // phpcs:ignore WordPress.DB.SlowDBQuery
            if ((string) $order->get_meta('_bs_vat_number') !== $result['vat_id']) {
                continue;
            }
            $order->update_meta_data('_bs_vat_status', $result['status']);
            $order->save();
            $order->add_order_note(self::note($result));
        }
        if ($result['status'] === BS_Vat::INVALID && $previous === BS_Vat::UNCHECKED) {
            BS_Orders::alert(
                __('USt-IdNr. nachträglich als ungültig erkannt', 'bs-connector'),
                sprintf("Kunde #%d (%s): Die bei VIES zunächst nicht prüfbare USt-IdNr. %s ist laut VIES ungültig.\n%s", $id, $user->user_email, $result['vat_id'], get_edit_user_link($id))
            );
        }
    }

    private static function recheck_order(int $order_id, int $attempt): void
    {
        $order = wc_get_order($order_id);
        if (! $order || (string) $order->get_meta('_bs_vat_status') !== BS_Vat::UNCHECKED) {
            return;
        }
        $result = self::check(self::order_vat($order) ?: (string) $order->get_meta('_bs_vat_number'), (string) $order->get_billing_country(), false);
        if ($result === null) {
            return;
        }
        if ($result['status'] === BS_Vat::UNCHECKED) {
            if ($result['retry']) {
                self::schedule_retry('order', $order_id, $attempt);
            }

            return;
        }
        $order->update_meta_data('_bs_vat_status', $result['status']);
        $order->save();
        $order->add_order_note(self::note($result));
    }

    // ---------------------------------------------------------------- Anzeige

    public static function status_label(string $status): string
    {
        $labels = [
            BS_Vat::VALID => __('gültig (VIES)', 'bs-connector'),
            BS_Vat::INVALID => __('ungültig', 'bs-connector'),
            BS_Vat::UNCHECKED => __('nicht geprüft (VIES nicht erreichbar)', 'bs-connector'),
            BS_Vat::NOT_APPLICABLE => __('nicht geprüft (kein EU-Land)', 'bs-connector'),
        ];

        return $labels[$status] ?? $status;
    }

    /** Text der Bestellnotiz. */
    public static function note(array $result): string
    {
        $note = sprintf(
            /* translators: 1: USt-IdNr., 2: Ergebnis */
            __('USt-IdNr. %1$s: %2$s', 'bs-connector'),
            $result['vat_id'],
            self::status_label((string) $result['status'])
        );
        if (! empty($result['checked_at'])) {
            $note .= ' – '.sprintf(
                $result['status'] === BS_Vat::UNCHECKED
                    ? /* translators: %s: Zeitpunkt */ __('Prüfversuch am %s', 'bs-connector')
                    : /* translators: %s: Zeitpunkt */ __('geprüft am %s', 'bs-connector'),
                wp_date('d.m.Y H:i', (int) $result['checked_at'])
            );
        }
        if (! empty($result['request_identifier'])) {
            $note .= ' – '.sprintf( /* translators: %s: Abfragenummer von VIES */ __('Abfragenummer %s', 'bs-connector'), $result['request_identifier']);
        }
        if (! empty($result['error']) && $result['status'] !== BS_Vat::VALID) {
            $note .= ' ('.$result['error'].')';
        }
        if ($result['status'] === BS_Vat::UNCHECKED && ! empty($result['retry'])) {
            $note .= ' – '.__('wird automatisch erneut geprüft', 'bs-connector');
        }

        return $note;
    }

    public static function render_profile(WP_User $user): void
    {
        if (! current_user_can('edit_user', $user->ID)) {
            return;
        }
        $result = self::user_result($user->ID);
        echo '<h2>'.esc_html__('USt-IdNr.-Prüfung (VIES)', 'bs-connector').'</h2>';
        echo '<table class="form-table" role="presentation">';
        if ($result === null) {
            echo '<tr><th>'.esc_html__('Ergebnis', 'bs-connector').'</th><td>'.esc_html__('Keine USt-IdNr. geprüft.', 'bs-connector').'</td></tr></table>';

            return;
        }
        $rows = [
            __('USt-IdNr.', 'bs-connector') => $result['vat_id'],
            __('Ergebnis', 'bs-connector') => self::status_label($result['status']).($result['error'] && $result['status'] !== BS_Vat::VALID ? ' ('.$result['error'].')' : ''),
            __('Geprüft am', 'bs-connector') => $result['checked_at'] ? wp_date('d.m.Y H:i:s', $result['checked_at']) : '–',
            __('Abfragenummer (VIES)', 'bs-connector') => $result['request_identifier'] !== '' ? $result['request_identifier'] : '–',
            __('Versuche', 'bs-connector') => (string) (int) get_user_meta($user->ID, '_bs_vat_attempts', true),
        ];
        foreach ($rows as $label => $value) {
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string) $value).'</td></tr>';
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=bs_connector_vat_recheck&user_id='.$user->ID), 'bs_connector_vat_recheck_'.$user->ID);
        echo '<tr><th></th><td><a class="button" href="'.esc_url($url).'">'.esc_html__('Jetzt erneut prüfen', 'bs-connector').'</a></td></tr>';
        echo '</table>';
    }

    public static function handle_admin_recheck(): void
    {
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        check_admin_referer('bs_connector_vat_recheck_'.$user_id);
        if (! current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        // Als erster Versuch: neue Wiederholungsfolge, falls VIES nicht antwortet.
        update_user_meta($user_id, '_bs_vat_status', BS_Vat::UNCHECKED);
        self::recheck('user', $user_id, 1);
        wp_safe_redirect(add_query_arg('bs_vat_rechecked', 1, get_edit_user_link($user_id)));
        exit;
    }
}
