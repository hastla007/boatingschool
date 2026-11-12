<?php
/**
 * Kundenkonten: B2B-Angaben bei der Registrierung, Abgleich mit der App
 * (asynchron über Action Scheduler) und „Mein Konto → Bootsschule
 * verknüpfen“ (Einmalcode aus der App). Passwörter werden nie übertragen.
 */
defined('ABSPATH') || exit;

final class BS_Accounts
{
    const ENDPOINT = 'bootsschule';

    const STATUS_CACHE = 'bs_connector_status_';

    public static function init(): void
    {
        add_action('woocommerce_register_form', [__CLASS__, 'registration_fields']);
        add_filter('woocommerce_registration_errors', [__CLASS__, 'validate_registration'], 10, 3);
        add_action('woocommerce_created_customer', [__CLASS__, 'on_created_customer']);
        add_action('woocommerce_customer_save_address', [__CLASS__, 'queue_update']);
        add_action('profile_update', [__CLASS__, 'queue_update']);
        add_action('delete_user', [__CLASS__, 'queue_deactivate']);
        add_action('bs_connector_sync_account', [__CLASS__, 'sync_account'], 10, 2);

        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_'.self::ENDPOINT.'_endpoint', [__CLASS__, 'render_endpoint']);
        add_action('template_redirect', [__CLASS__, 'handle_link_form']);
    }

    public static function add_endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    // ---------------------------------------------------------------- Registrierung

    public static function registration_fields(): void
    {
        $company = isset($_POST['billing_company']) ? sanitize_text_field(wp_unslash($_POST['billing_company'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $country = isset($_POST['billing_country']) ? sanitize_text_field(wp_unslash($_POST['billing_country'])) : 'DE'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $vat = isset($_POST['bs_vat_id']) ? sanitize_text_field(wp_unslash($_POST['bs_vat_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ?>
		<p class="form-row form-row-wide">
			<label for="reg_billing_company"><?php esc_html_e('Name der Bootsschule (Unternehmen)', 'bs-connector'); ?>&nbsp;<span class="required">*</span></label>
			<input type="text" class="input-text" name="billing_company" id="reg_billing_company" value="<?php echo esc_attr($company); ?>" required>
		</p>
		<p class="form-row form-row-wide">
			<label for="reg_billing_country"><?php esc_html_e('Rechnungsland (ISO-Code, z. B. DE)', 'bs-connector'); ?>&nbsp;<span class="required">*</span></label>
			<input type="text" class="input-text" name="billing_country" id="reg_billing_country" maxlength="2" value="<?php echo esc_attr($country); ?>" required>
		</p>
		<p class="form-row form-row-wide">
			<label for="reg_bs_vat_id"><?php esc_html_e('USt-IdNr. (falls vorhanden)', 'bs-connector'); ?></label>
			<input type="text" class="input-text" name="bs_vat_id" id="reg_bs_vat_id" value="<?php echo esc_attr($vat); ?>">
		</p>
		<?php
    }

    public static function validate_registration(WP_Error $errors, $username, $email): WP_Error
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce des Formulars.
        if (empty($_POST['billing_company'])) {
            $errors->add('billing_company', __('Bitte den Namen der Bootsschule angeben.', 'bs-connector'));
        }
        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')));
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            $errors->add('billing_country', __('Bitte das Rechnungsland als zweistelligen Code angeben (z. B. DE).', 'bs-connector'));
        }
        // phpcs:enable

        return $errors;
    }

    public static function on_created_customer(int $customer_id): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (isset($_POST['billing_company'])) {
            update_user_meta($customer_id, 'billing_company', sanitize_text_field(wp_unslash($_POST['billing_company'])));
        }
        if (isset($_POST['billing_country'])) {
            update_user_meta($customer_id, 'billing_country', strtoupper(sanitize_text_field(wp_unslash($_POST['billing_country']))));
        }
        if (! empty($_POST['bs_vat_id'])) {
            update_user_meta($customer_id, BS_Settings::get()['vat_meta_key'], sanitize_text_field(wp_unslash($_POST['bs_vat_id'])));
        }
        // phpcs:enable
        // Vom Provisionierungs-Endpunkt angelegte Konten kennt die App schon.
        if (get_user_meta($customer_id, '_bs_provisioned', true)) {
            return;
        }
        self::enqueue('register', $customer_id);
    }

    public static function queue_update($user_id): void
    {
        if (get_user_meta((int) $user_id, '_bs_registered', true)) {
            self::enqueue('update', (int) $user_id);
        }
    }

    public static function queue_deactivate($user_id): void
    {
        if (get_user_meta((int) $user_id, '_bs_registered', true)) {
            // Sofort senden: nach dem Löschen gibt es den Nutzer nicht mehr.
            self::sync_account('deactivate', (int) $user_id);
        }
    }

    private static function enqueue(string $action, int $customer_id): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('bs_connector_sync_account', [$action, $customer_id], 'bs-connector');
        } else {
            self::sync_account($action, $customer_id);
        }
    }

    /** Abgleich mit der App; bei Fehler erneut in 5 Minuten (höchstens dreimal). */
    public static function sync_account(string $action, int $customer_id): void
    {
        $user = get_userdata($customer_id);
        if (! $user && $action !== 'deactivate') {
            return;
        }
        $data = $user ? [
            'email' => $user->user_email,
            'first_name' => (string) get_user_meta($customer_id, 'first_name', true),
            'last_name' => (string) get_user_meta($customer_id, 'last_name', true),
            'company_name' => (string) get_user_meta($customer_id, 'billing_company', true),
            'billing_country' => (string) get_user_meta($customer_id, 'billing_country', true),
            'vat_id' => (string) get_user_meta($customer_id, BS_Settings::get()['vat_meta_key'], true),
        ] : [];
        $data = array_filter($data, static fn ($v) => $v !== '');

        if ($action === 'register') {
            $response = BS_Api_Client::request('POST', '/accounts', ['shop_customer_id' => (string) $customer_id] + $data);
        } elseif ($action === 'update') {
            $response = BS_Api_Client::request('PATCH', '/accounts/'.$customer_id, $data);
        } else {
            $response = BS_Api_Client::request('POST', '/accounts/'.$customer_id.'/deactivate');
        }

        if ($response['status'] && $response['status'] < 300) {
            if ($action === 'register') {
                update_user_meta($customer_id, '_bs_registered', 1);
            }
            delete_transient(self::STATUS_CACHE.$customer_id);

            return;
        }

        $attempts = (int) get_user_meta($customer_id, '_bs_sync_attempts', true) + 1;
        update_user_meta($customer_id, '_bs_sync_attempts', $attempts);
        if ($attempts < 3 && function_exists('as_schedule_single_action') && $user) {
            as_schedule_single_action(time() + 5 * MINUTE_IN_SECONDS, 'bs_connector_sync_account', [$action, $customer_id], 'bs-connector');

            return;
        }
        BS_Orders::alert(
            __('Kontenabgleich mit der Bootsschul-Plattform fehlgeschlagen', 'bs-connector'),
            sprintf('Kunde #%d, Aktion %s: %s', $customer_id, $action, (string) $response['error'])
        );
    }

    // ---------------------------------------------------------------- Status / Verknüpfung

    /** Status in der App (5 Minuten zwischengespeichert). @return array{status: string, can_purchase: bool, tenant: ?array}|null */
    public static function status(int $customer_id, bool $fresh = false): ?array
    {
        if (! $fresh) {
            $cached = get_transient(self::STATUS_CACHE.$customer_id);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $response = BS_Api_Client::request('GET', '/accounts/'.$customer_id);
        if ($response['status'] !== 200) {
            return null;
        }
        $status = [
            'status' => (string) ($response['body']['data']['status'] ?? ''),
            'can_purchase' => (bool) ($response['body']['data']['can_purchase'] ?? false),
            'tenant' => $response['body']['data']['tenant'] ?? null,
        ];
        set_transient(self::STATUS_CACHE.$customer_id, $status, 5 * MINUTE_IN_SECONDS);

        return $status;
    }

    public static function menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);
        $items[self::ENDPOINT] = __('Bootsschule verknüpfen', 'bs-connector');
        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    public static function handle_link_form(): void
    {
        if (! is_user_logged_in() || empty($_POST['bs_link_code'])) {
            return;
        }
        if (! isset($_POST['_bs_link_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bs_link_nonce'])), 'bs_link')) {
            wc_add_notice(__('Sitzung abgelaufen, bitte erneut versuchen.', 'bs-connector'), 'error');

            return;
        }
        $customer_id = get_current_user_id();
        // Noch nicht registriert (z. B. Altbestand): erst anmelden lassen.
        if (! get_user_meta($customer_id, '_bs_registered', true)) {
            self::sync_account('register', $customer_id);
        }
        $code = sanitize_text_field(wp_unslash($_POST['bs_link_code']));
        $response = BS_Api_Client::request('POST', '/accounts/'.$customer_id.'/link', ['link_code' => $code]);
        delete_transient(self::STATUS_CACHE.$customer_id);

        if ($response['status'] === 200) {
            $tenant = $response['body']['data']['tenant']['name'] ?? '';
            wc_add_notice(sprintf( /* translators: %s: Name der Bootsschule */ __('Ihr Shopkonto ist jetzt mit „%s“ verknüpft.', 'bs-connector'), $tenant), 'success');
        } elseif ($response['status'] === 429) {
            wc_add_notice(__('Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.', 'bs-connector'), 'error');
        } else {
            wc_add_notice(__('Der Verknüpfungscode ist ungültig oder abgelaufen. Bitte in der App einen neuen erzeugen.', 'bs-connector'), 'error');
        }
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    public static function render_endpoint(): void
    {
        $status = self::status(get_current_user_id(), true);
        echo '<h2>'.esc_html__('Bootsschule verknüpfen', 'bs-connector').'</h2>';
        if ($status && $status['can_purchase']) {
            echo '<p>'.esc_html(sprintf( /* translators: %s: Name der Bootsschule */ __('Verknüpft mit: %s. Gekaufte Codes werden nach der Zahlung dem Codebestand dieser Bootsschule gutgeschrieben.', 'bs-connector'), $status['tenant']['name'] ?? '')).'</p>';

            return;
        }
        if ($status && $status['status'] === 'pending_review') {
            echo '<p>'.esc_html__('Ihr Konto wird gerade geprüft. Sie erhalten eine E-Mail, sobald Ihre Bootsschule freigeschaltet ist.', 'bs-connector').'</p>';
        }
        if ($status && $status['status'] === 'rejected') {
            echo '<p>'.esc_html__('Für dieses Konto ist kein Einkauf für eine Bootsschule möglich. Bitte wenden Sie sich an den Support.', 'bs-connector').'</p>';

            return;
        }
        echo '<p>'.esc_html__('Haben Sie bereits Zugang zur Plattform? Erzeugen Sie dort im Admin-Bereich unter „Codes“ einen Verknüpfungscode und geben Sie ihn hier ein.', 'bs-connector').'</p>';
        ?>
		<form method="post" class="woocommerce-form">
			<?php wp_nonce_field('bs_link', '_bs_link_nonce'); ?>
			<p class="form-row">
				<label for="bs_link_code"><?php esc_html_e('Verknüpfungscode', 'bs-connector'); ?></label>
				<input type="text" class="input-text" name="bs_link_code" id="bs_link_code" placeholder="XXXX-XXXX" autocomplete="off" maxlength="20" required>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e('Verknüpfen', 'bs-connector'); ?></button></p>
		</form>
		<?php
    }
}
