<?php

/**
 * Zwei eigene Feldtypen für Support-Anfragen (Entscheidung des Inhabers vom 16.11.2026), über die
 * Erweiterungspunkte von Form Captain ab Contract 1.1.0 (docs/spec/api-v1.md „Erweiterungspunkte für Pro-Funktionen“):
 *
 * - `boatingschool_products` („Meine Produkte“, has_value): Auswahl der Produkte, die der auf der Website angemeldete
 *   WooCommerce-Kunde gekauft hat. Quelle nur WooCommerce-CRUD (`wc_get_orders` mit `customer_id` = aktueller
 *   Benutzer, bezahlte Status). Der Wert wird serverseitig gegen die eigenen Käufe des aktuellen Benutzers geprüft –
 *   fremde Produkt-IDs werden abgelehnt. Gespeichert wird JSON `{"product_id":…,"customer_id":…}`. Ohne Anmeldung je
 *   nach Feldeinstellung ein Hinweis (Standard) oder ein Freitextfeld (`{"text":"…"}`).
 * - `boatingschool_customer` („Kundeninfo“, ohne Wert): zeigt Name, E-Mail und Kundennummer des angemeldeten Kunden nur
 *   an. Form Captain kennt beim Rendern keine Vorbelegung (Prefill); die Anzeige läuft deshalb über diesen eigenen
 *   Feldtyp und wird nie gespeichert oder übertragen.
 *
 * Die Kundenreferenz (Kunden-ID; die Konto-E-Mail wird erst bei der Zustellung gelesen) steckt im Wert des
 * Produktfelds, weil der Worker von Form Captain ohne angemeldeten Benutzer läuft.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class CustomerFields
{
    public const PRODUCTS = 'boatingschool_products';

    public const CUSTOMER = 'boatingschool_customer';

    /** Höchstzahl gelesener Bestellungen je Anfrage (neueste zuerst). */
    public const MAX_ORDERS = 100;

    /** @var array<int,array<int,string>> Benutzer-ID → [Produkt-ID → Name] */
    private static array $cache = [];

    public static function register(): void
    {
        // Eigene Feldtypen gibt es erst ab Contract 1.1.0; auf 1.0.x bleibt der Connector ohne sie nutzbar.
        if (has_filter('form_captain_field_types_v1', [self::class, 'types'])
            || ! function_exists('form_captain_contract_version') || version_compare((string) form_captain_contract_version(), '1.1.0', '<')) {
            return;
        }
        add_filter('form_captain_field_types_v1', [self::class, 'types']);
        add_filter('form_captain_render_field_v1', [self::class, 'render'], 10, 3);
        add_filter('form_captain_validate_field_v1', [self::class, 'validate'], 10, 4);
        add_filter('form_captain_display_value_v1', [self::class, 'display'], 10, 3);
        add_filter('form_captain_field_error_message_v1', [self::class, 'error_message'], 10, 3);
        add_filter('form_captain_editor_extensions_v1', [self::class, 'editor_panels']);
        add_filter('form_captain_normalize_field_v1', [self::class, 'normalize'], 10, 3);
    }

    /** @param array<string,array<string,mixed>> $types */
    public static function types(array $types): array
    {
        return $types + [
            self::PRODUCTS => ['label' => __('Meine Produkte (WooCommerce)', 'boatingschool-form-connector'), 'has_value' => true],
            self::CUSTOMER => ['label' => __('Kundeninfo (WooCommerce, nur Anzeige)', 'boatingschool-form-connector'), 'has_value' => false],
        ];
    }

    /**
     * @param  array<string,mixed>  $field
     * @param  array<string,mixed>  $ctx
     */
    public static function render(mixed $html, array $field, array $ctx): mixed
    {
        $type = (string) ($field['type'] ?? '');
        if ($type === self::CUSTOMER) {
            return self::render_customer();
        }
        if ($type !== self::PRODUCTS) {
            return $html;
        }
        $id = esc_attr((string) ($ctx['id'] ?? ''));
        $name = esc_attr((string) ($ctx['name'] ?? ''));
        $aria = ($ctx['aria-describedby'] ?? '') !== '' ? ' aria-describedby="'.esc_attr((string) $ctx['aria-describedby']).'"' : '';
        $required = ! empty($ctx['required']) ? ' required' : '';
        $invalid = ! empty($ctx['invalid']) ? ' aria-invalid="true"' : '';
        $user = self::user_id();
        if ($user === 0) {
            if (self::guest_mode($field) === 'text') {
                return '<input type="text" id="'.$id.'" name="'.$name.'" maxlength="200"'.$required.$invalid.$aria.' placeholder="'.esc_attr__('Produkt (z. B. Name des Kurses)', 'boatingschool-form-connector').'">';
            }

            return '<p id="'.$id.'" class="fc-x-notice">'.esc_html__('Bitte melden Sie sich mit Ihrem Kundenkonto an, um Ihr Produkt auszuwählen.', 'boatingschool-form-connector').'</p>';
        }
        $products = self::purchased_products($user);
        if ($products === []) {
            return '<p id="'.$id.'" class="fc-x-notice">'.esc_html__('In Ihrem Kundenkonto sind keine gekauften Produkte zu finden.', 'boatingschool-form-connector').'</p>';
        }
        $selected = is_scalar($ctx['value'] ?? null) ? (string) $ctx['value'] : '';
        $out = '<select id="'.$id.'" name="'.$name.'"'.$required.$invalid.$aria.'><option value="">'.esc_html__('Bitte wählen', 'boatingschool-form-connector').'</option>';
        foreach ($products as $product_id => $label) {
            $out .= '<option value="'.esc_attr((string) $product_id).'"'.($selected === (string) $product_id ? ' selected' : '').'>'.esc_html($label).'</option>';
        }

        return $out.'</select>';
    }

    /**
     * Serverseitige Prüfung: nur eigene, bezahlte Käufe des aktuellen Benutzers.
     *
     * @param  array<string,mixed>  $field
     * @param  string|array<int,string>|null  $value
     * @param  array<string,mixed>  $ctx
     */
    public static function validate(mixed $result, array $field, mixed $value, array $ctx): mixed
    {
        $type = (string) ($field['type'] ?? '');
        if ($type === self::CUSTOMER) {
            return ['value' => null]; // reine Anzeige, nie ein Wert
        }
        if ($type !== self::PRODUCTS) {
            return $result;
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        $required = ! empty($field['required']);
        $user = self::user_id();
        if ($user === 0) {
            if (self::guest_mode($field) === 'text' && $value !== '') {
                return ['value' => (string) wp_json_encode(['text' => mb_substr(sanitize_text_field($value), 0, 200)])];
            }

            return $required ? ['code' => self::guest_mode($field) === 'text' ? 'required' : 'login_required'] : ['value' => null];
        }
        if ($value === '') {
            return $required ? ['code' => 'required'] : ['value' => null];
        }
        if (! ctype_digit($value) || ! array_key_exists((int) $value, self::purchased_products($user))) {
            return ['code' => 'product_not_purchased'];
        }

        return ['value' => (string) wp_json_encode(['product_id' => (int) $value, 'customer_id' => $user])];
    }

    /**
     * Klartext für Admin, CSV und Mail.
     *
     * @param  array<string,mixed>  $field
     */
    public static function display(string $display, array $field, mixed $value): string
    {
        if ((string) ($field['type'] ?? '') !== self::PRODUCTS) {
            return $display;
        }
        $data = self::decode($value);
        if (isset($data['text'])) {
            return $data['text'].' '.__('(Freitext, ohne Anmeldung)', 'boatingschool-form-connector');
        }
        if (! isset($data['product_id'])) {
            return $display;
        }
        $product = self::product($data['product_id']);
        $text = $product['name'] !== '' ? $product['name'] : '#'.$data['product_id'];
        if ($product['sku'] !== '') {
            $text .= ' (SKU '.$product['sku'].')';
        }

        return isset($data['customer_id']) ? $text.' · '.sprintf(__('Kundennummer %d', 'boatingschool-form-connector'), $data['customer_id']) : $text;
    }

    /** @param array<string,mixed> $field */
    public static function error_message(string $message, string $code, array $field): string
    {
        return match ($code) {
            'login_required' => __('Bitte melden Sie sich an, um ein Produkt auszuwählen.', 'boatingschool-form-connector'),
            'product_not_purchased' => __('Bitte wählen Sie eines Ihrer gekauften Produkte.', 'boatingschool-form-connector'),
            default => $message,
        };
    }

    /**
     * Feldeinstellung im Editor: Verhalten ohne Anmeldung.
     *
     * @param  array<int,array<string,mixed>>  $panels
     */
    public static function editor_panels(array $panels): array
    {
        $panels[] = [
            'key' => 'boatingschool_products',
            'scope' => 'field',
            'label' => __('Meine Produkte', 'boatingschool-form-connector'),
            'description' => __('Zeigt angemeldeten Kunden ihre gekauften Produkte (WooCommerce).', 'boatingschool-form-connector'),
            'types' => [self::PRODUCTS],
            'controls' => [[
                'key' => 'guest_mode',
                'type' => 'select',
                'label' => __('Ohne Anmeldung', 'boatingschool-form-connector'),
                'options' => ['notice' => __('Hinweis „Bitte anmelden“', 'boatingschool-form-connector'), 'text' => __('Freitextfeld für das Produkt', 'boatingschool-form-connector')],
            ]],
        ];

        return $panels;
    }

    /**
     * @param  array<string,mixed>  $raw_field
     * @param  array<string,string>  $ids
     */
    public static function normalize(mixed $result, array $raw_field, array $ids): mixed
    {
        if ((string) ($raw_field['type'] ?? '') !== self::PRODUCTS) {
            return $result;
        }
        $mode = (string) ($raw_field['x']['guest_mode'] ?? 'notice');

        return ['settings' => ['guest_mode' => $mode === 'text' ? 'text' : 'notice']];
    }

    /**
     * Gekaufte Produkte (bezahlte Bestellungen) eines Kunden, nur über WooCommerce-CRUD.
     *
     * @return array<int,string> Produkt-ID → Name
     */
    public static function purchased_products(int $user_id): array
    {
        if ($user_id < 1 || ! function_exists('wc_get_orders')) {
            return [];
        }
        if (isset(self::$cache[$user_id])) {
            return self::$cache[$user_id];
        }
        $statuses = function_exists('wc_get_is_paid_statuses') ? (array) wc_get_is_paid_statuses() : ['processing', 'completed'];
        $orders = wc_get_orders(['customer_id' => $user_id, 'status' => $statuses, 'limit' => self::MAX_ORDERS, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects']);
        $products = [];
        foreach ((array) $orders as $order) {
            if (! is_object($order) || ! method_exists($order, 'get_items') || (int) $order->get_customer_id() !== $user_id) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                if (! is_object($item) || ! method_exists($item, 'get_product_id')) {
                    continue;
                }
                $product_id = (int) $item->get_product_id();
                if ($product_id > 0 && ! isset($products[$product_id])) {
                    $products[$product_id] = (string) $item->get_name();
                }
            }
        }

        return self::$cache[$user_id] = $products;
    }

    /** @return array{name:string,sku:string} */
    public static function product(int $product_id): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (! is_object($product)) {
            return ['name' => '', 'sku' => ''];
        }

        return ['name' => (string) $product->get_name(), 'sku' => (string) $product->get_sku()];
    }

    /** E-Mail aus dem Kundenkonto (WooCommerce, sonst WordPress-Konto). */
    public static function customer_email(int $customer_id): string
    {
        if (class_exists('WC_Customer')) {
            try {
                $email = (string) (new \WC_Customer($customer_id))->get_email();
                if ($email !== '') {
                    return $email;
                }
            } catch (\Throwable) {
                // Kunde nicht (mehr) vorhanden.
            }
        }
        $user = get_userdata($customer_id);

        return is_object($user) ? (string) $user->user_email : '';
    }

    /** @return array{product_id?:int,customer_id?:int,text?:string} */
    public static function decode(mixed $value): array
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $data = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($data)) {
            return [];
        }
        $out = [];
        if (isset($data['product_id']) && is_int($data['product_id']) && $data['product_id'] > 0) {
            $out['product_id'] = $data['product_id'];
        }
        if (isset($data['customer_id']) && is_int($data['customer_id']) && $data['customer_id'] > 0) {
            $out['customer_id'] = $data['customer_id'];
        }
        if (isset($data['text']) && is_string($data['text']) && trim($data['text']) !== '') {
            $out['text'] = mb_substr(trim($data['text']), 0, 200);
        }

        return $out;
    }

    public static function reset_cache(): void
    {
        self::$cache = [];
    }

    private static function render_customer(): string
    {
        $user = self::user_id();
        if ($user === 0) {
            return '<p class="fc-x-notice">'.esc_html__('Melden Sie sich an, damit wir Ihre Kundendaten sehen.', 'boatingschool-form-connector').'</p>';
        }
        $name = '';
        $email = '';
        if (class_exists('WC_Customer')) {
            try {
                $customer = new \WC_Customer($user);
                $name = trim($customer->get_first_name().' '.$customer->get_last_name());
                $email = (string) $customer->get_email();
            } catch (\Throwable) {
                // Kein WooCommerce-Kunde: WordPress-Konto.
            }
        }
        $account = get_userdata($user);
        $name = $name !== '' ? $name : (is_object($account) ? (string) $account->display_name : '');
        $email = $email !== '' ? $email : (is_object($account) ? (string) $account->user_email : '');

        return '<dl class="fc-x-customer">'
            .'<dt>'.esc_html__('Name', 'boatingschool-form-connector').'</dt><dd>'.esc_html($name).'</dd>'
            .'<dt>'.esc_html__('E-Mail', 'boatingschool-form-connector').'</dt><dd>'.esc_html($email).'</dd>'
            .'<dt>'.esc_html__('Kundennummer', 'boatingschool-form-connector').'</dt><dd>'.esc_html((string) $user).'</dd>'
            .'</dl>';
    }

    /** @param array<string,mixed> $field */
    private static function guest_mode(array $field): string
    {
        return ($field['x']['guest_mode'] ?? 'notice') === 'text' ? 'text' : 'notice';
    }

    private static function user_id(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}
