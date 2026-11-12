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
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_fields']);
    }

    /** @return array{courses: array, products: array} Katalog der App (15 Minuten zwischengespeichert). */
    public static function catalog(): array
    {
        $cached = get_transient('bs_connector_catalog');
        if (is_array($cached)) {
            return $cached;
        }
        $response = BS_Api_Client::request('GET', '/catalog');
        $catalog = [
            'courses' => $response['body']['data']['courses'] ?? [],
            'products' => $response['body']['data']['products'] ?? [],
        ];
        if ($response['status'] === 200) {
            set_transient('bs_connector_catalog', $catalog, 15 * MINUTE_IN_SECONDS);
        }

        return $catalog;
    }

    /** Liefert das Produkt Codes der Plattform? */
    public static function is_platform_product($product): bool
    {
        return $product instanceof WC_Product && (string) $product->get_meta(self::META_KIND) !== '';
    }

    /** @return array{kind: string, course_code: ?string, product_code: ?string, option_product_codes: array} */
    public static function target(WC_Product $product): array
    {
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
        woocommerce_wp_text_input(
            [
                'id' => self::META_OPTIONS,
                'label' => __('Zusatzprodukte (Codes, kommagetrennt)', 'bs-connector'),
                'description' => __('Nur bei Kurscodes: wird beim Einlösen zusätzlich gutgeschrieben.', 'bs-connector'),
                'desc_tip' => true,
            ]
        );
        echo '</div>';
    }

    public static function save_fields(int $post_id): void
    {
        // Nonce und Rechte prüft WooCommerce vor diesem Hook (woocommerce_process_product_meta).
        $product = wc_get_product($post_id);
        if (! $product) {
            return;
        }
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
        $product->save();
    }
}
