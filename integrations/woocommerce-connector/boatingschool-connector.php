<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Plugin Name:       Bootsschul-Plattform Connector
 * Description:       Verkauft Kurs-, Verlängerungs- und Produktcodes der Bootsschul-Plattform. Nach der Zahlung werden die Codes im Codebestand der verknüpften Bootsschule hinterlegt.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Text Domain:       bs-connector
 * License:           Proprietary
 */
defined('ABSPATH') || exit;

define('BS_CONNECTOR_VERSION', '1.0.0');
define('BS_CONNECTOR_FILE', __FILE__);

foreach (['signature', 'delivery-policy', 'settings', 'api-client', 'products', 'accounts', 'checkout', 'orders', 'provision-endpoint'] as $bs_connector_file) {
    require_once __DIR__.'/includes/class-bs-'.$bs_connector_file.'.php';
}

// Kompatibel mit den HPOS-Bestelltabellen von WooCommerce.
add_action(
    'before_woocommerce_init',
    static function () {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', BS_CONNECTOR_FILE, true);
        }
    }
);

add_action(
    'plugins_loaded',
    static function () {
        if (! class_exists('WooCommerce')) {
            add_action(
                'admin_notices',
                static function () {
                    echo '<div class="notice notice-error"><p>'.esc_html__('Der Bootsschul-Plattform Connector benötigt WooCommerce.', 'bs-connector').'</p></div>';
                }
            );

            return;
        }
        BS_Settings::init();
        BS_Products::init();
        BS_Accounts::init();
        BS_Checkout::init();
        BS_Orders::init();
        BS_Provision_Endpoint::init();
    }
);

register_activation_hook(
    __FILE__,
    static function () {
        BS_Accounts::add_endpoint();
        flush_rewrite_rules();
    }
);
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
