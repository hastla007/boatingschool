<?php

/**
 * Endpunkt für die App (App -> Shop): legt für eine vom Superadmin neu
 * angelegte Bootsschule das Kundenkonto im Shop an.
 *
 *   POST /wp-json/boatingschool/v1/provision  (signiert, X-BS-*)
 *
 * Gibt es bereits ein Konto, das früher für dieselbe App-Person angelegt
 * wurde, wird es wiederverwendet. Ein fremdes Konto mit derselben E-Mail
 * wird NICHT übernommen (E-Mail-Gleichheit verknüpft nie): die App erfährt
 * das über matched_by=email, und die Verknüpfung erfolgt per Einmalcode.
 * Passwörter werden nie übertragen; neue Kunden erhalten den
 * WordPress-Link zum Setzen des Passworts.
 */
defined('ABSPATH') || exit;

final class BS_Provision_Endpoint
{
    const NAMESPACE_V1 = 'boatingschool/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register(): void
    {
        register_rest_route(
            self::NAMESPACE_V1,
            '/provision',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle'],
                'permission_callback' => [__CLASS__, 'verify'],
            ]
        );
    }

    /** @return true|WP_Error */
    public static function verify(WP_REST_Request $request)
    {
        $settings = BS_Settings::get();
        $key_id = (string) $request->get_header('x_bs_key');
        $ts = (string) $request->get_header('x_bs_timestamp');
        $nonce = (string) $request->get_header('x_bs_nonce');
        $sig = (string) $request->get_header('x_bs_signature');
        // Fester Pfad, den die App signiert und aufruft (unabhängig von einem
        // per Filter geänderten REST-Präfix oder ?rest_route=-Aufrufen).
        $path = '/wp-json/'.self::NAMESPACE_V1.'/provision';

        if ($settings['inbound_secret'] === '' || ! hash_equals($settings['inbound_key_id'], $key_id)
            || ! BS_Signature::verify($settings['inbound_secret'], 'POST', $path, $ts, $nonce, (string) $request->get_body(), $sig)) {
            return new WP_Error('bs_signature_invalid', 'Signatur ungültig oder abgelaufen.', ['status' => 401]);
        }
        // Wiederholte Nonce innerhalb des Zeitfensters ablehnen. Die Anlage ist
        // ohnehin idempotent; das hier verhindert nur das Abspielen alter Anfragen.
        $nonce_key = 'bs_nonce_'.md5($nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error('bs_signature_replayed', 'Anfrage wurde bereits verarbeitet.', ['status' => 401]);
        }
        set_transient($nonce_key, 1, 2 * BS_Signature::TOLERANCE);

        return true;
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data = (array) $request->get_json_params();
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $app_user_id = sanitize_text_field((string) ($data['app_user_id'] ?? ''));
        $tenant_id = sanitize_text_field((string) ($data['app_tenant_id'] ?? ''));
        if (! is_email($email) || $app_user_id === '' || $tenant_id === '') {
            return new WP_REST_Response(['message' => 'email, app_user_id und app_tenant_id sind Pflicht.'], 422);
        }

        // 1) Früher für dieselbe App-Person angelegt.
        $users = get_users(
            [
                'meta_key' => 'bs_app_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value' => $app_user_id, // phpcs:ignore WordPress.DB.SlowDBQuery
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        if ($users) {
            $user_id = (int) $users[0];
            update_user_meta($user_id, 'bs_app_tenant_id', $tenant_id);
            BS_Log::info('Provisionierung: bestehendes Kundenkonto wiederverwendet', ['customer_id' => $user_id, 'matched_by' => 'app_user']);

            return new WP_REST_Response(['customer_id' => $user_id, 'created' => false, 'matched_by' => 'app_user'], 200);
        }

        // 2) Fremdes Konto mit derselben Adresse: nicht übernehmen.
        $existing = get_user_by('email', $email);
        if ($existing) {
            BS_Log::info('Provisionierung: fremdes Kundenkonto mit derselben Adresse, nicht übernommen', ['customer_id' => (int) $existing->ID, 'matched_by' => 'email']);

            return new WP_REST_Response(['customer_id' => (int) $existing->ID, 'created' => false, 'matched_by' => 'email'], 200);
        }

        // 3) Neu anlegen (ohne woocommerce_created_customer: die App kennt das Konto schon).
        $login = sanitize_user(strtok($email, '@'), true);
        $base = $login === '' ? 'bootsschule' : $login;
        $login = $base;
        $counter = 1;
        while (username_exists($login)) {
            $login = $base.'-'.(++$counter);
        }
        $user_id = wp_insert_user(
            [
                'user_login' => $login,
                'user_email' => $email,
                'user_pass' => wp_generate_password(32, true, true),
                'first_name' => sanitize_text_field((string) ($data['first_name'] ?? '')),
                'last_name' => sanitize_text_field((string) ($data['last_name'] ?? '')),
                'role' => 'customer',
            ]
        );
        if (is_wp_error($user_id)) {
            BS_Log::error('Provisionierung fehlgeschlagen', ['error' => $user_id->get_error_message()]);

            return new WP_REST_Response(['message' => $user_id->get_error_message()], 500);
        }
        update_user_meta($user_id, 'billing_email', $email);
        update_user_meta($user_id, 'billing_company', sanitize_text_field((string) ($data['company_name'] ?? '')));
        update_user_meta($user_id, 'bs_app_user_id', $app_user_id);
        update_user_meta($user_id, 'bs_app_tenant_id', $tenant_id);
        update_user_meta($user_id, '_bs_provisioned', 1);
        update_user_meta($user_id, '_bs_registered', 1);
        // Die Adresse kommt aus der Plattform (dort bestätigt): keine Bestätigungsmail.
        update_user_meta($user_id, '_bs_email_confirmed', strtolower($email));
        BS_Log::info('Kundenkonto für neue Bootsschule angelegt', ['customer_id' => (int) $user_id, 'matched_by' => 'created']);
        // Link zum Setzen des Passworts (kein Passwort-Austausch mit der App).
        wp_new_user_notification($user_id, null, 'user');

        return new WP_REST_Response(['customer_id' => (int) $user_id, 'created' => true, 'matched_by' => 'created'], 201);
    }
}
