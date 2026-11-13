<?php

/**
 * Zuordnung eines Shop-Produkts zu dem, was die Plattform liefert:
 * Kurscode, Verlängerungscode, Produktcode (z. B. Fahrstunde) oder Kurscode
 * mit Zusatzprodukten. Die Auswahl kommt aus dem Katalog der App.
 */
defined('ABSPATH') || exit;

final class BS_Products
{
    const META_KIND = '_bs_kind';

    const META_COURSE = '_bs_course_code';

    const META_PRODUCT = '_bs_product_code';

    const META_OPTIONS = '_bs_option_product_codes';

    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_fields']);
        // Bekommt das Produktobjekt, bevor WooCommerce es speichert (sonst
        // überschreibt WooCommerce z. B. „virtuell“ mit dem Formularwert).
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
        // Höchstmenge in den Mengenfeldern (klassisch und Block-Warenkorb); erzwungen
        // wird sie zusätzlich serverseitig im Warenkorb und an der Kasse.
        add_filter('woocommerce_quantity_input_args', [__CLASS__, 'quantity_args'], 10, 2);
        add_filter('woocommerce_store_api_product_quantity_maximum', [__CLASS__, 'max_quantity'], 10, 2);
    }

    /** @return array{courses: array, products: array, option_products: array, max_quantity: ?int} Katalog der App (15 Minuten zwischengespeichert). */
    public static function catalog(): array
    {
        $cached = get_transient('bs_connector_catalog');
        if (is_array($cached)) {
            return $cached;
        }
        $response = BS_Api_Client::request('GET', '/catalog');
        $catalog = [
            'courses' => $response['body']['data']['courses'] ?? [],
            // Produktcodes: nur allein verkäufliche Produkte der Plattform.
            'products' => $response['body']['data']['products'] ?? [],
            // Zusatzprodukte zu Kurscodes: alle aktiven Produkte (ältere Plattformen: wie products).
            'option_products' => $response['body']['data']['option_products'] ?? ($response['body']['data']['products'] ?? []),
            'max_quantity' => isset($response['body']['data']['max_quantity']) ? (int) $response['body']['data']['max_quantity'] : null,
        ];
        if ($response['status'] === 200) {
            set_transient('bs_connector_catalog', $catalog, 15 * MINUTE_IN_SECONDS);
        }

        return $catalog;
    }

    /** Höchstmenge je Bestellposition, falls der Katalog keine liefert (wie COMMERCE max_quantity der Plattform). */
    const MAX_QUANTITY = 500;

    /** Höchstmenge je Bestellposition laut Katalog der Plattform, sonst MAX_QUANTITY. */
    public static function max_quantity_limit(): int
    {
        // Je Seitenaufruf nur einmal ermitteln (Mengenfelder fragen je Produkt).
        static $limit = null;
        if ($limit === null) {
            $value = (int) (self::catalog()['max_quantity'] ?? 0);
            $limit = $value > 0 ? $value : self::MAX_QUANTITY;
        }

        return $limit;
    }

    /** Die Zuordnung steht am Hauptprodukt -- auch für dessen Varianten. */
    public static function source($product): ?WC_Product
    {
        if (! $product instanceof WC_Product) {
            return null;
        }
        if ($product->is_type('variation') && $product->get_parent_id()) {
            return wc_get_product($product->get_parent_id()) ?: null;
        }

        return $product;
    }

    /** Liefert das Produkt Codes der Plattform? */
    public static function is_platform_product($product): bool
    {
        $source = self::source($product);

        return $source !== null && (string) $source->get_meta(self::META_KIND) !== '';
    }

    public static function quantity_args($args, $product)
    {
        if (is_array($args) && self::is_platform_product($product)) {
            $args['max_value'] = self::max_quantity($args['max_value'] ?? -1, $product);
        }

        return $args;
    }

    public static function max_quantity($max, $product)
    {
        if (! self::is_platform_product($product)) {
            return $max;
        }

        $limit = self::max_quantity_limit();

        return ($max === -1 || $max === '' || (int) $max <= 0) ? $limit : min((int) $max, $limit);
    }

    /** @return array{kind: string, course_code: ?string, product_code: ?string, option_product_codes: array} */
    public static function target(WC_Product $product): array
    {
        $product = self::source($product) ?? $product;
        $options = array_values(array_filter(array_map('trim', explode(',', (string) $product->get_meta(self::META_OPTIONS)))));

        return [
            'kind' => (string) $product->get_meta(self::META_KIND),
            'course_code' => (string) $product->get_meta(self::META_COURSE) ?: null,
            'product_code' => (string) $product->get_meta(self::META_PRODUCT) ?: null,
            'option_product_codes' => $options,
        ];
    }

    public static function render_fields(): void
    {
        $catalog = self::catalog();
        $courses = ['' => __('— wählen —', 'bs-connector')];
        foreach ($catalog['courses'] as $course) {
            $courses[$course['code']] = $course['name'].' ('.$course['code'].')';
        }
        $products = ['' => __('— wählen —', 'bs-connector')];
        foreach ($catalog['products'] as $item) {
            $products[$item['code']] = $item['name'].' ('.$item['code'].')';
        }
        // Gespeicherte Zuordnung immer anbieten -- auch wenn der Katalog gerade
        // nicht erreichbar ist oder den Eintrag nicht mehr enthält. Sonst würde
        // ein Speichern die Zuordnung still löschen.
        $post_id = (int) get_the_ID();
        foreach ([self::META_COURSE => &$courses, self::META_PRODUCT => &$products] as $meta => &$options) {
            $stored = (string) get_post_meta($post_id, $meta, true);
            if ($stored !== '' && ! isset($options[$stored])) {
                $options[$stored] = $stored.' '.__('(nicht im aktuellen Katalog)', 'bs-connector');
            }
        }
        unset($options);

        echo '<div class="options_group">';
        woocommerce_wp_select(
            [
                'id' => self::META_KIND,
                'label' => __('Bootsschul-Codes', 'bs-connector'),
                'description' => __('Je verkaufter Menge entsteht ein Code im Codebestand der Bootsschule.', 'bs-connector'),
                'desc_tip' => true,
                'options' => [
                    '' => __('Keine (normales Produkt)', 'bs-connector'),
                    'course' => __('Kurscode', 'bs-connector'),
                    'renewal' => __('Verlängerungscode', 'bs-connector'),
                    'product' => __('Produktcode (z. B. Fahrstunde)', 'bs-connector'),
                ],
            ]
        );
        woocommerce_wp_select(['id' => self::META_COURSE, 'label' => __('Kurs', 'bs-connector'), 'options' => $courses]);
        woocommerce_wp_select(['id' => self::META_PRODUCT, 'label' => __('Plattform-Produkt', 'bs-connector'), 'options' => $products]);
        $option_codes = implode(', ', array_map(static fn ($item) => (string) $item['code'], (array) ($catalog['option_products'] ?? [])));
        woocommerce_wp_text_input(
            [
                'id' => self::META_OPTIONS,
                'label' => __('Zusatzprodukte (Codes, kommagetrennt)', 'bs-connector'),
                'description' => __('Nur bei Kurscodes: wird beim Einlösen zusätzlich gutgeschrieben.', 'bs-connector')
                    .($option_codes === '' ? '' : ' '.sprintf( /* translators: %s: Codes der Zusatzprodukte */ __('Verfügbar: %s', 'bs-connector'), $option_codes)),
                'desc_tip' => true,
            ]
        );
        echo '</div>';
    }

    public static function save_fields(WC_Product $product): void
    {
        // Nonce und Rechte prüft WooCommerce vor diesem Hook.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $kind = sanitize_key(wp_unslash($_POST[self::META_KIND] ?? ''));
        $kind = in_array($kind, ['course', 'renewal', 'product'], true) ? $kind : '';
        $product->update_meta_data(self::META_KIND, $kind);
        $product->update_meta_data(self::META_COURSE, sanitize_text_field(wp_unslash($_POST[self::META_COURSE] ?? '')));
        $product->update_meta_data(self::META_PRODUCT, sanitize_text_field(wp_unslash($_POST[self::META_PRODUCT] ?? '')));
        $product->update_meta_data(self::META_OPTIONS, sanitize_text_field(wp_unslash($_POST[self::META_OPTIONS] ?? '')));
        // phpcs:enable
        if ($kind !== '') {
            // Codes sind digitale Ware: kein Versand.
            $product->set_virtual(true);
        }
        // Gespeichert wird von WooCommerce direkt nach diesem Hook.
    }
}
