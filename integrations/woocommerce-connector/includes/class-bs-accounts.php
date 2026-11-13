<?php
/**
 * Kundenkonten: B2B-Angaben bei der Registrierung, Bestätigung der
 * E-Mail-Adresse, Abgleich mit der App (asynchron über Action Scheduler) und
 * „Mein Konto → Bootsschule verknüpfen“ (Einmalcode von der Plattform).
 * Passwörter werden nie übertragen.
 *
 * Bei der Plattform angemeldet wird ein neues Konto erst, wenn die Adresse
 * über den Link aus der Bestätigungsmail bestätigt ist. Vom Provisionierungs-
 * Endpunkt angelegte Konten gelten als bestätigt.
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
        add_action('bs_connector_sync_account', [__CLASS__, 'sync_account'], 10, 3);

        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_item']);
        add_action('woocommerce_account_'.self::ENDPOINT.'_endpoint', [__CLASS__, 'render_endpoint']);
        add_action('template_redirect', [__CLASS__, 'handle_verification_link']);
        add_action('template_redirect', [__CLASS__, 'handle_resend_form']);
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
        // Nur das Registrierungsformular unter „Mein Konto“ -- Konten, die an der
        // Kasse angelegt werden (normale Produkte), brauchen keine Schulangaben.
        if (did_action('woocommerce_checkout_process') || ! isset($_POST['register'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return $errors;
        }
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
        // Erst nach bestätigter Adresse bei der Plattform anmelden.
        self::send_verification($customer_id);
    }

    // ---------------------------------------------------------------- E-Mail-Bestätigung

    public static function email_confirmed(int $customer_id): bool
    {
        $user = get_userdata($customer_id);
        if (! $user) {
            return false;
        }

        return BS_Email_Verification::is_confirmed(
            [
                '_bs_provisioned' => get_user_meta($customer_id, '_bs_provisioned', true),
                '_bs_email_confirmed' => get_user_meta($customer_id, '_bs_email_confirmed', true),
            ],
            (string) $user->user_email
        );
    }

    /** Geheimnis für die Bestätigungslinks (aus den Schlüsseln der wp-config.php). */
    private static function verification_secret(): string
    {
        return hash_hmac('sha256', 'bs-connector-email-verification', wp_salt('auth'));
    }

    /** Bestätigungsmail mit signiertem Link senden; ein neuer Link macht den alten ungültig. */
    public static function send_verification(int $customer_id): bool
    {
        $user = get_userdata($customer_id);
        if (! $user || ! is_email($user->user_email)) {
            return false;
        }
        $key = wp_generate_password(20, false);
        $expires = time() + BS_Email_Verification::TTL;
        update_user_meta($customer_id, '_bs_email_verify_key', $key);
        update_user_meta($customer_id, '_bs_email_verify_sent', time());
        $url = add_query_arg(
            [
                'bs_verify' => $customer_id,
                'bs_expires' => $expires,
                'bs_token' => BS_Email_Verification::token(self::verification_secret(), $customer_id, (string) $user->user_email, $key, $expires),
            ],
            wc_get_page_permalink('myaccount')
        );
        $shop = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $sent = wp_mail(
            $user->user_email,
            sprintf( /* translators: %s: Name des Shops */ __('[%s] Bitte bestätigen Sie Ihre E-Mail-Adresse', 'bs-connector'), $shop),
            sprintf(
                /* translators: 1: Name des Shops, 2: Bestätigungslink, 3: Gültigkeit in Tagen */
                __("Guten Tag,\n\nSie haben bei %1\$s ein Kundenkonto angelegt. Bitte bestätigen Sie Ihre E-Mail-Adresse über diesen Link:\n\n%2\$s\n\nDer Link ist %3\$d Tage gültig. Erst danach können Sie Ihr Konto mit Ihrer Bootsschule verknüpfen und Codes kaufen.\n\nFalls Sie kein Konto angelegt haben, können Sie diese E-Mail ignorieren.", 'bs-connector'),
                $shop,
                $url,
                (int) (BS_Email_Verification::TTL / DAY_IN_SECONDS)
            )
        );
        // Ohne Link und Token im Protokoll.
        BS_Log::info('Bestätigungsmail gesendet', ['customer_id' => $customer_id, 'mail_sent' => (bool) $sent]);

        return (bool) $sent;
    }

    /** Link aus der Bestätigungsmail (auch ohne Anmeldung: der Link selbst weist aus). */
    public static function handle_verification_link(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- signierter Link statt Nonce.
        if (! isset($_GET['bs_verify'], $_GET['bs_expires'], $_GET['bs_token'])) {
            return;
        }
        $customer_id = absint($_GET['bs_verify']);
        $expires = absint($_GET['bs_expires']);
        $token = sanitize_text_field(wp_unslash($_GET['bs_token']));
        // phpcs:enable
        $user = $customer_id ? get_userdata($customer_id) : false;

        if ($user && self::email_confirmed($customer_id)) {
            self::notice(__('Ihre E-Mail-Adresse ist bereits bestätigt.', 'bs-connector'), 'success');
        } elseif ($user && BS_Email_Verification::verify(self::verification_secret(), $customer_id, (string) $user->user_email, (string) get_user_meta($customer_id, '_bs_email_verify_key', true), $expires, $token)) {
            update_user_meta($customer_id, '_bs_email_confirmed', strtolower((string) $user->user_email));
            // Einmal verwendbar.
            delete_user_meta($customer_id, '_bs_email_verify_key');
            BS_Log::info('E-Mail-Adresse bestätigt', ['customer_id' => $customer_id]);
            if (! get_user_meta($customer_id, '_bs_registered', true)) {
                self::enqueue('register', $customer_id);
            }
            self::notice(__('Vielen Dank, Ihre E-Mail-Adresse ist bestätigt. Sie können Ihr Konto jetzt mit Ihrer Bootsschule verknüpfen.', 'bs-connector'), 'success');
        } else {
            BS_Log::warning('Ungültiger oder abgelaufener Bestätigungslink', ['customer_id' => $customer_id]);
            self::notice(__('Der Bestätigungslink ist ungültig oder abgelaufen. Melden Sie sich an und fordern Sie unter „Mein Konto → Bootsschule verknüpfen“ einen neuen Link an.', 'bs-connector'), 'error');
        }
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    /** „Bestätigungsmail erneut senden“ unter „Mein Konto → Bootsschule verknüpfen“. */
    public static function handle_resend_form(): void
    {
        if (! is_user_logged_in() || empty($_POST['bs_resend_verification'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if (! isset($_POST['_bs_resend_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_bs_resend_nonce'])), 'bs_resend_verification')) {
            self::notice(__('Sitzung abgelaufen, bitte erneut versuchen.', 'bs-connector'), 'error');

            return;
        }
        $customer_id = get_current_user_id();
        if (self::email_confirmed($customer_id)) {
            self::notice(__('Ihre E-Mail-Adresse ist bereits bestätigt.', 'bs-connector'), 'success');
        } elseif (! BS_Email_Verification::can_resend((int) get_user_meta($customer_id, '_bs_email_verify_sent', true))) {
            self::notice(__('Wir haben Ihnen gerade erst einen Link geschickt. Bitte warten Sie eine Minute, bevor Sie einen neuen anfordern.', 'bs-connector'), 'notice');
        } elseif (self::send_verification($customer_id)) {
            self::notice(sprintf( /* translators: %s: E-Mail-Adresse */ __('Wir haben Ihnen einen neuen Bestätigungslink an %s geschickt.', 'bs-connector'), wp_get_current_user()->user_email), 'success');
        } else {
            self::notice(__('Die E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.', 'bs-connector'), 'error');
        }
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    /** Hinweis für die nächste Seite (auch für Gäste, die den Link öffnen). */
    private static function notice(string $message, string $type): void
    {
        if (! function_exists('wc_add_notice') || ! function_exists('WC') || ! WC()->session) {
            return;
        }
        if (! WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        wc_add_notice($message, $type);
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
            // Sofort senden (Wiederholungen brauchen den Nutzer nicht mehr).
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

    /** Abgleich mit der App; bei Fehler erneut nach 5 Minuten (höchstens drei Versuche je Vorgang). */
    public static function sync_account(string $action, int $customer_id, int $attempt = 1): void
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
            'vat_id' => self::vat_id_for_app($customer_id),
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

        if ($attempt < 3 && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 5 * MINUTE_IN_SECONDS, 'bs_connector_sync_account', [$action, $customer_id, $attempt + 1], 'bs-connector');

            return;
        }
        BS_Orders::alert(
            __('Kontenabgleich mit der Bootsschul-Plattform fehlgeschlagen', 'bs-connector'),
            sprintf('Kunde #%d, Aktion %s: %s', $customer_id, $action, (string) $response['error'])
        );
    }

    /** USt-IdNr. ohne Leer- und Trennzeichen, mit Länderkennung (z. B. „DE 811 191 002“ → DE811191002). */
    private static function vat_id_for_app(int $customer_id): string
    {
        $entered = (string) get_user_meta($customer_id, BS_Settings::get()['vat_meta_key'], true);
        $normalized = BS_Vat::normalize($entered, (string) get_user_meta($customer_id, 'billing_country', true));

        return $normalized === null ? '' : $normalized['vat_id'];
    }

    // ---------------------------------------------------------------- Status / Verknüpfung

    /**
     * Status in der App (5 Minuten zwischengespeichert).
     *
     * deliverable_course_codes: Kurse, die die verknüpfte Schule derzeit erhalten
     * kann; null, wenn die App die Angabe (noch) nicht liefert.
     *
     * @return array{status: string, can_purchase: bool, tenant: ?array, deliverable_course_codes: ?array}|null
     */
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
            'deliverable_course_codes' => is_array($response['body']['data']['deliverable_course_codes'] ?? null)
                ? array_values(array_map('strval', $response['body']['data']['deliverable_course_codes']))
                : null,
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
        // Unbestätigte Adresse: keine Verknüpfung (und keine Anmeldung bei der Plattform).
        if (! self::email_confirmed($customer_id)) {
            wc_add_notice(__('Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse über den Link in unserer E-Mail.', 'bs-connector'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
            exit;
        }
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
        } elseif ($response['status'] === 409) {
            wc_add_notice(__('Für dieses Kundenkonto ist keine Verknüpfung möglich. Bitte wenden Sie sich an den Support.', 'bs-connector'), 'error');
        } elseif ($response['status'] === null || $response['status'] === 404 || $response['status'] >= 500) {
            wc_add_notice(__('Die Bootsschul-Plattform ist gerade nicht erreichbar. Bitte versuchen Sie es später erneut.', 'bs-connector'), 'error');
        } else {
            wc_add_notice(__('Der Verknüpfungscode ist ungültig oder abgelaufen. Bitte auf der Plattform einen neuen erzeugen.', 'bs-connector'), 'error');
        }
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    public static function render_endpoint(): void
    {
        $customer_id = get_current_user_id();
        echo '<h2>'.esc_html__('Bootsschule verknüpfen', 'bs-connector').'</h2>';
        if (! self::email_confirmed($customer_id)) {
            echo '<p>'.esc_html(sprintf( /* translators: %s: E-Mail-Adresse */ __('Ihre E-Mail-Adresse %s ist noch nicht bestätigt. Bitte öffnen Sie den Link in unserer E-Mail. Erst danach können Sie Ihr Konto mit Ihrer Bootsschule verknüpfen.', 'bs-connector'), wp_get_current_user()->user_email)).'</p>';
            ?>
			<form method="post" class="woocommerce-form">
				<?php wp_nonce_field('bs_resend_verification', '_bs_resend_nonce'); ?>
				<input type="hidden" name="bs_resend_verification" value="1">
				<p><button type="submit" class="button"><?php esc_html_e('Bestätigungsmail erneut senden', 'bs-connector'); ?></button></p>
			</form>
			<?php

            return;
        }
        $status = self::status($customer_id, true);
        if ($status && $status['can_purchase']) {
            echo '<p>'.esc_html(sprintf( /* translators: %s: Name der Bootsschule */ __('Verknüpft mit: %s. Gekaufte Codes werden nach der Zahlung dem Codebestand dieser Bootsschule gutgeschrieben.', 'bs-connector'), $status['tenant']['name'] ?? '')).'</p>';

            return;
        }
        if ($status && $status['status'] === 'pending_review') {
            echo '<p>'.esc_html__('Ihr Konto ist noch nicht für den Einkauf freigeschaltet. Wir prüfen Ihre Angaben und melden uns per E-Mail.', 'bs-connector').'</p>';
        }
        if ($status && $status['status'] === 'rejected') {
            echo '<p>'.esc_html__('Für dieses Konto ist kein Einkauf für eine Bootsschule möglich. Bitte wenden Sie sich an den Support.', 'bs-connector').'</p>';

            return;
        }
        echo '<p>'.esc_html__('Haben Sie bereits Zugang zur Plattform? Erzeugen Sie dort im Admin-Bereich unter „Zugangscodes → Shop verknüpfen“ einen Verknüpfungscode und geben Sie ihn hier ein.', 'bs-connector').'</p>';
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
