<?php

/**
 * Einkaufssperre: Produkte mit Bootsschul-Codes kann nur kaufen, wer
 * angemeldet und mit einer aktiven Bootsschule verknüpft ist. Ist die
 * Plattform nicht erreichbar, wird vorsichtshalber gesperrt.
 */
defined('ABSPATH') || exit;

final class BS_Checkout
{
    public static function init(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 2);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart']);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout'], 10, 2);
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'delivery_notice']);
        add_action('woocommerce_before_cart_totals', [__CLASS__, 'delivery_notice']);
        // Block-Checkout (Store API) löst woocommerce_after_checkout_validation nicht aus.
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'validate_block_checkout']);
    }

    /** @return string|null Fehlermeldung oder null, wenn der Einkauf möglich ist. */
    public static function purchase_blocker(bool $fresh = false): ?string
    {
        if (! is_user_logged_in()) {
            return __('Bootsschul-Codes können nur mit einem Kundenkonto gekauft werden. Bitte melden Sie sich an.', 'bs-connector');
        }
        $status = BS_Accounts::status(get_current_user_id(), $fresh);
        if ($status === null) {
            return __('Die Bootsschul-Plattform ist gerade nicht erreichbar oder Ihr Konto ist dort noch nicht bekannt. Bitte versuchen Sie es später erneut.', 'bs-connector');
        }
        if (! $status['can_purchase']) {
            return $status['status'] === 'pending_review'
                ? __('Ihr Konto ist noch nicht für den Einkauf freigeschaltet. Wir prüfen Ihre Angaben; haben Sie bereits Zugang zur Plattform, können Sie Ihr Konto auch selbst unter „Mein Konto → Bootsschule verknüpfen“ verknüpfen.', 'bs-connector')
                : __('Bitte verknüpfen Sie Ihr Kundenkonto zuerst unter „Mein Konto → Bootsschule verknüpfen“ mit Ihrer Bootsschule.', 'bs-connector');
        }

        return null;
    }

    private static function cart_has_platform_products(): bool
    {
        if (! WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (BS_Products::is_platform_product($item['data'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public static function validate_add_to_cart($passed, $product_id)
    {
        if (! $passed || ! BS_Products::is_platform_product(wc_get_product($product_id))) {
            return $passed;
        }
        $blocker = self::purchase_blocker();
        if ($blocker !== null) {
            wc_add_notice($blocker, 'error');

            return false;
        }

        return true;
    }

    /** Mehr als die Höchstmenge je Position nimmt die Plattform nicht an. */
    private static function quantity_blocker(): ?string
    {
        foreach (WC()->cart ? WC()->cart->get_cart() : [] as $item) {
            if (BS_Products::is_platform_product($item['data'] ?? null) && (int) ($item['quantity'] ?? 0) > BS_Products::MAX_QUANTITY) {
                return sprintf(
                    /* translators: %d: Höchstmenge */
                    __('Bootsschul-Codes können höchstens %d Stück je Position bestellt werden.', 'bs-connector'),
                    BS_Products::MAX_QUANTITY
                );
            }
        }

        return null;
    }

    public static function check_cart(): void
    {
        if (self::cart_has_platform_products()) {
            $blocker = self::purchase_blocker() ?? self::quantity_blocker();
            if ($blocker !== null) {
                wc_add_notice($blocker, 'error');
            }
        }
    }

    /** Unmittelbar vor der Bestellung: Status frisch abfragen (kein Cache). */
    public static function validate_checkout($data, WP_Error $errors): void
    {
        if (self::cart_has_platform_products()) {
            $blocker = self::purchase_blocker(true) ?? self::quantity_blocker();
            if ($blocker !== null) {
                $errors->add('bs_connector', $blocker);
            }
        }
    }

    /** Dasselbe für den Block-Checkout. */
    public static function validate_block_checkout($order): void
    {
        if (! self::cart_has_platform_products()) {
            return;
        }
        $blocker = self::purchase_blocker(true) ?? self::quantity_blocker();
        if ($blocker === null) {
            return;
        }
        $exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        if (class_exists($exception)) {
            throw new $exception('bs_connector_blocked', $blocker, 403);
        }
        throw new Exception($blocker);
    }

    public static function delivery_notice(): void
    {
        if (! self::cart_has_platform_products() || self::purchase_blocker() !== null) {
            return;
        }
        $status = BS_Accounts::status(get_current_user_id());
        echo '<p class="bs-connector-notice">'.esc_html(
            sprintf(
                /* translators: %s: Name der Bootsschule */
                __('Die Codes werden nach Zahlungseingang dem Codebestand von „%s“ auf der Plattform gutgeschrieben. Es wird nichts versandt.', 'bs-connector'),
                $status['tenant']['name'] ?? ''
            )
        ).'</p>';
    }
}
