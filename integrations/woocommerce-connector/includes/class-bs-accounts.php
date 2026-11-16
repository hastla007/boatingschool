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
 *
 * Ausdrücklicher Abgleich (ohne Prüfsummen-Vergleich): Knopf im Nutzerprofil,
 * Sammelaktion in der Nutzerliste und `wp bs-connector resync` (BS_CLI).
 */
defined('ABSPATH') || exit;

final class BS_Accounts
{
    const ENDPOINT = 'bootsschule';

    const STATUS_CACHE = 'bs_connector_status_';

    /** Sperre für frische Abfragen je Kunde (siehe status()). */
    const STATUS_FRESH_LOCK = 'bs_connector_status_fresh_';

    /** Gültigkeit eines positiven Status (Sekunden). */
    const STATUS_TTL = 300;

    /** Gültigkeit eines Fehlschlags (404/429/5xx/Netzfehler), Sekunden. */
    const STATUS_NEGATIVE_TTL = 60;

    /** Höchstens eine frische Abfrage je Kunde in diesem Abstand (Sekunden). */
    const STATUS_FRESH_INTERVAL = 10;

    /** admin-post-Aktion, Nonce-Präfix und Sammelaktion für „Konto neu abgleichen“. */
    const RESYNC_ACTION = 'bs_connector_account_resync';

    public static function init(): void
    {
        add_action('woocommerce_register_form', [__CLASS__, 'registration_fields']);
        add_filter('woocommerce_registration_errors', [__CLASS__, 'validate_registration'], 10, 3);
        add_action('woocommerce_created_customer', [__CLASS__, 'on_created_customer']);
        add_action('woocommerce_customer_save_address', [__CLASS__, 'queue_update']);
        add_action('profile_update', [__CLASS__, 'queue_update']);
        // WooCommerce schreibt Rechnungsdaten erst nach profile_update (wp_update_user);
        // dieser Hook sieht den gespeicherten Stand.
        add_action('woocommerce_update_customer', [__CLASS__, 'queue_update']);
        add_action('delete_user', [__CLASS__, 'queue_deactivate']);
        add_action('bs_connector_sync_account', [__CLASS__, 'sync_account'], 10, 4);

        // Backend: Konto ausdrücklich neu abgleichen (nur Administratoren).
        add_action('show_user_profile', [__CLASS__, 'render_resync_profile']);
        add_action('edit_user_profile', [__CLASS__, 'render_resync_profile']);
        add_action('admin_post_'.self::RESYNC_ACTION, [__CLASS__, 'handle_admin_resync']);
        add_filter('bulk_actions-users', [__CLASS__, 'resync_bulk_action']);
        add_filter('handle_bulk_actions-users', [__CLASS__, 'handle_resync_bulk_action'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'resync_notices']);

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

    /**
     * Profil oder Adresse gespeichert: Abgleich nur, wenn sich die abgeglichenen
     * Felder seit dem letzten erfolgreichen Abgleich geändert haben. WooCommerce
     * speichert den Kunden z. B. bei jeder Bestellung (profile_update) -- ohne
     * diese Prüfung ginge jedes Mal ein PATCH an die Plattform. Geänderte
     * Rechnungsdaten erkennt woocommerce_update_customer (nach dem Speichern der
     * Metadaten); sync_account liest die Daten bei der Ausführung ohnehin neu.
     */
    public static function queue_update($user_id): void
    {
        $user_id = (int) $user_id;
        if (! get_user_meta($user_id, '_bs_registered', true)) {
            return;
        }
        $user = get_userdata($user_id);
        if ($user && self::already_synced($user_id, self::account_payload($user_id, $user))) {
            return;
        }
        self::enqueue('update', $user_id);
    }

    public static function queue_deactivate($user_id): void
    {
        if (get_user_meta((int) $user_id, '_bs_registered', true)) {
            // Sofort senden (Wiederholungen brauchen den Nutzer nicht mehr).
            self::sync_account('deactivate', (int) $user_id);
        }
    }

    private static function enqueue(string $action, int $customer_id, bool $force = false): void
    {
        // force nur mitgeben, wenn gesetzt: gewöhnliche Aufgaben behalten ihre Argumente.
        $args = $force ? [$action, $customer_id, 1, true] : [$action, $customer_id];
        if (function_exists('as_enqueue_async_action')) {
            if ($force && function_exists('as_has_scheduled_action') && as_has_scheduled_action('bs_connector_sync_account', $args, 'bs-connector')) {
                return; // schon eingeplant (z. B. Knopf zweimal geklickt)
            }
            as_enqueue_async_action('bs_connector_sync_account', $args, 'bs-connector');
        } else {
            self::sync_account($action, $customer_id, 1, $force);
        }
    }

    /** Beim Shop registriertes (und damit ggf. verknüpftes) Konto? */
    public static function is_registered(int $customer_id): bool
    {
        return (bool) get_user_meta($customer_id, '_bs_registered', true);
    }

    /**
     * Ausdrücklichen Abgleich einplanen (PATCH /accounts/{id} mit force, also
     * ohne Prüfsummen-Vergleich), z. B. nachdem das Konto auf der Plattform
     * geändert oder zurückgesetzt wurde. false bei nicht registrierten Konten.
     */
    public static function queue_resync(int $customer_id): bool
    {
        if (! self::is_registered($customer_id) || ! get_userdata($customer_id)) {
            return false;
        }
        self::enqueue('update', $customer_id, true);
        BS_Log::info('Kontoabgleich ausdrücklich eingeplant', ['customer_id' => $customer_id]);

        return true;
    }

    /**
     * Abgleich mit der App; bei Fehler erneut nach 5 Minuten (höchstens drei Versuche je Vorgang).
     *
     * Ein Update, dessen Daten schon erfolgreich abgeglichen sind, entfällt auch
     * hier (z. B. zweimal eingeplant, weil in einer Anfrage profile_update und
     * woocommerce_customer_save_address feuern) -- außer mit $force (ausdrücklicher
     * Abgleich; gilt auch für dessen Wiederholungen). Die Prüfsumme wird erst nach
     * erfolgreicher Antwort gesetzt; nach einem Fehler sendet der nächste
     * Versuch also wieder.
     *
     * Ergebnis (für WP-CLI): result = synced, skipped, retry (Wiederholung
     * eingeplant) oder failed (Alarm gesendet), dazu HTTP-Status und Fehler.
     *
     * @return array{result: string, status: ?int, error: ?string}
     */
    public static function sync_account(string $action, int $customer_id, int $attempt = 1, bool $force = false): array
    {
        $user = get_userdata($customer_id);
        if (! $user && $action !== 'deactivate') {
            return ['result' => 'skipped', 'status' => null, 'error' => 'Nutzer nicht gefunden'];
        }
        $data = $user ? self::account_payload($customer_id, $user) : [];
        if ($action === 'update' && ! $force && self::already_synced($customer_id, $data)) {
            return ['result' => 'skipped', 'status' => null, 'error' => null];
        }

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
            if ($action !== 'deactivate') {
                // Prüfsumme der zuletzt erfolgreich abgeglichenen Kontaktdaten.
                update_user_meta($customer_id, '_bs_account_sync_hash', self::payload_hash($data));
            }
            self::forget_status($customer_id);

            return ['result' => 'synced', 'status' => (int) $response['status'], 'error' => null];
        }
        $error = (string) ($response['error'] ?? ('HTTP '.$response['status']));

        if ($attempt < 3 && function_exists('as_schedule_single_action')) {
            $args = [$action, $customer_id, $attempt + 1];
            if ($force) {
                $args[] = true;
            }
            as_schedule_single_action(time() + 5 * MINUTE_IN_SECONDS, 'bs_connector_sync_account', $args, 'bs-connector');

            return ['result' => 'retry', 'status' => $response['status'], 'error' => $error];
        }
        BS_Orders::alert(
            __('Kontenabgleich mit der Bootsschul-Plattform fehlgeschlagen', 'bs-connector'),
            sprintf('Kunde #%d, Aktion %s: %s', $customer_id, $action, $error)
        );

        return ['result' => 'failed', 'status' => $response['status'], 'error' => $error];
    }

    // ---------------------------------------------------------------- Backend: neu abgleichen

    /**
     * Recht für den ausdrücklichen Abgleich: Administratoren (BS_Settings::CAPABILITY,
     * wie Einstellungen und Verbindungstest), die dieses Nutzerkonto bearbeiten dürfen.
     */
    public static function can_resync(int $customer_id): bool
    {
        return BS_Settings::can_manage() && current_user_can('edit_user', $customer_id);
    }

    /** Box im Nutzerprofil mit dem Knopf (nur registrierte Konten, nur mit Recht). */
    public static function render_resync_profile(WP_User $user): void
    {
        $customer_id = (int) $user->ID;
        if (! self::can_resync($customer_id) || ! self::is_registered($customer_id)) {
            return;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action='.self::RESYNC_ACTION.'&user_id='.$customer_id), self::RESYNC_ACTION.'_'.$customer_id);
        echo '<h2>'.esc_html__('Bootsschul-Plattform', 'bs-connector').'</h2>';
        echo '<table class="form-table" role="presentation"><tr><th>'.esc_html__('Kontoabgleich', 'bs-connector').'</th><td>';
        echo '<a class="button" href="'.esc_url($url).'">'.esc_html__('Konto mit der Bootsschul-Plattform neu abgleichen', 'bs-connector').'</a>';
        echo '<p class="description">'.esc_html__('Sendet E-Mail, Name, Firma, Rechnungsland und USt-IdNr. erneut an die Plattform, auch wenn sie sich im Shop nicht geändert haben (z. B. nachdem das Konto auf der Plattform geändert oder zurückgesetzt wurde).', 'bs-connector').'</p>';
        echo '</td></tr></table>';
    }

    public static function handle_admin_resync(): void
    {
        $customer_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        check_admin_referer(self::RESYNC_ACTION.'_'.$customer_id);
        if (! self::can_resync($customer_id)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        $queued = self::queue_resync($customer_id);
        wp_safe_redirect(add_query_arg(['bs_resynced' => $queued ? 1 : 0, 'bs_resync_skipped' => $queued ? 0 : 1], get_edit_user_link($customer_id)));
        exit;
    }

    public static function resync_bulk_action(array $actions): array
    {
        if (BS_Settings::can_manage()) {
            $actions[self::RESYNC_ACTION] = __('Mit der Bootsschul-Plattform neu abgleichen', 'bs-connector');
        }

        return $actions;
    }

    /** Sammelaktion: nur registrierte Konten, die der Administrator bearbeiten darf. */
    public static function handle_resync_bulk_action($redirect_to, $action, $user_ids)
    {
        if ($action !== self::RESYNC_ACTION) {
            return $redirect_to;
        }
        // Nonce der Nutzerliste (WP_List_Table: bulk-users).
        check_admin_referer('bulk-users');
        if (! BS_Settings::can_manage()) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-connector'), 403);
        }
        [$queued, $skipped] = [0, 0];
        foreach (array_slice(array_map('intval', (array) $user_ids), 0, 200) as $customer_id) {
            if (self::can_resync($customer_id) && self::queue_resync($customer_id)) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return add_query_arg(['bs_resynced' => $queued, 'bs_resync_skipped' => $skipped], $redirect_to);
    }

    public static function resync_notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
        if (! isset($_GET['bs_resynced']) || ! BS_Settings::can_manage()) {
            return;
        }
        $queued = absint($_GET['bs_resynced']);
        $skipped = absint($_GET['bs_resync_skipped'] ?? 0);
        // phpcs:enable
        if ($queued > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sprintf(
                /* translators: %d: Anzahl Konten */
                _n('Abgleich eingeplant (%d Konto).', 'Abgleich eingeplant (%d Konten).', $queued, 'bs-connector'),
                $queued
            )).'</p></div>';
        }
        if ($skipped > 0) {
            echo '<div class="notice notice-warning is-dismissible"><p>'.esc_html(sprintf(
                /* translators: %d: Anzahl Konten */
                _n('%d Konto übersprungen: nicht bei der Bootsschul-Plattform registriert.', '%d Konten übersprungen: nicht bei der Bootsschul-Plattform registriert.', $skipped, 'bs-connector'),
                $skipped
            )).'</p></div>';
        }
    }

    /**
     * Kontaktdaten, die mit der App abgeglichen werden (POST /accounts, PATCH
     * /accounts/{id}); leere Felder entfallen.
     *
     * @return array<string, string>
     */
    private static function account_payload(int $customer_id, $user): array
    {
        $data = [
            'email' => (string) $user->user_email,
            'first_name' => (string) get_user_meta($customer_id, 'first_name', true),
            'last_name' => (string) get_user_meta($customer_id, 'last_name', true),
            'company_name' => (string) get_user_meta($customer_id, 'billing_company', true),
            'billing_country' => (string) get_user_meta($customer_id, 'billing_country', true),
            'vat_id' => self::vat_id_for_app($customer_id),
        ];

        return array_filter($data, static fn ($v) => $v !== '');
    }

    /** Prüfsumme der Kontaktdaten (Reihenfolge der Felder egal). */
    private static function payload_hash(array $data): string
    {
        ksort($data);

        return hash('sha256', (string) wp_json_encode($data));
    }

    /** Sind genau diese Kontaktdaten schon erfolgreich abgeglichen? */
    private static function already_synced(int $customer_id, array $data): bool
    {
        $stored = (string) get_user_meta($customer_id, '_bs_account_sync_hash', true);

        return $stored !== '' && hash_equals($stored, self::payload_hash($data));
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
     * Status in der App, zwischengespeichert: Erfolg 5 Minuten, Fehlschlag
     * (404, 429, 5xx, Netzfehler) 60 Sekunden -- sonst könnte ein Kunde mit
     * jedem Seitenaufruf eine Anfrage an die App auslösen und deren
     * Begrenzung für den ganzen Shop aufbrauchen.
     *
     * $fresh (Kasse): lädt am Cache vorbei, aber höchstens einmal je
     * STATUS_FRESH_INTERVAL Sekunden und Kunde; innerhalb dieser Zeit gilt
     * das gerade geladene Ergebnis (es ist höchstens so alt).
     *
     * deliverable_course_codes: Kurse, die die verknüpfte Schule derzeit erhalten
     * kann; null, wenn die App die Angabe (noch) nicht liefert.
     *
     * @return array{status: string, can_purchase: bool, tenant: ?array, deliverable_course_codes: ?array}|null
     */
    public static function status(int $customer_id, bool $fresh = false): ?array
    {
        $cached = get_transient(self::STATUS_CACHE.$customer_id);
        $throttled = $fresh && get_transient(self::STATUS_FRESH_LOCK.$customer_id);
        if (is_array($cached) && (! $fresh || $throttled)) {
            return empty($cached['unavailable']) ? $cached : null;
        }
        if ($fresh) {
            set_transient(self::STATUS_FRESH_LOCK.$customer_id, 1, self::STATUS_FRESH_INTERVAL);
        }

        $response = BS_Api_Client::request('GET', '/accounts/'.$customer_id);
        if ($response['status'] !== 200) {
            // Fehlschlag kurz merken (Antwort bleibt null = Einkauf gesperrt).
            set_transient(self::STATUS_CACHE.$customer_id, ['unavailable' => true, 'http_status' => $response['status']], self::STATUS_NEGATIVE_TTL);

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
        set_transient(self::STATUS_CACHE.$customer_id, $status, self::STATUS_TTL);

        return $status;
    }

    /** Status verwerfen, damit der nächste Aufruf frisch lädt (nach Abgleich oder Verknüpfung). */
    public static function forget_status(int $customer_id): void
    {
        delete_transient(self::STATUS_CACHE.$customer_id);
        delete_transient(self::STATUS_FRESH_LOCK.$customer_id);
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
        // Die Seite nach der Weiterleitung lädt den Status dadurch einmal frisch.
        self::forget_status($customer_id);

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

    /**
     * „Mein Konto → Bootsschule verknüpfen“: zwischengespeicherter Status. Frisch
     * geladen wird nur direkt nach einem Verknüpfungsversuch (handle_link_form
     * verwirft den Cache vor der Weiterleitung) -- ein Neuladen der Seite löst
     * so keine Anfrage an die App aus.
     */
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
        $status = self::status($customer_id);
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
