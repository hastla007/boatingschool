<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Plugin Name:       Bootsschul-Plattform Connector
 * Description:       Verkauft Kurs-, Verlängerungs- und Produktcodes der Bootsschul-Plattform. Nach der Zahlung werden die Codes im Codebestand der verknüpften Bootsschule hinterlegt.
 * Version:           1.5.0
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.2
 * WC tested up to:   11.3
 * Text Domain:       bs-connector
 * Domain Path:       /languages
 * License:           Proprietary
 */
defined('ABSPATH') || exit;

define('BS_CONNECTOR_VERSION', '1.5.0');
define('BS_CONNECTOR_FILE', __FILE__);

// Mindestanforderungen (includes/class-bs-requirements.php): Aktivierung bricht
// ab, im laufenden Betrieb nur ein Hinweis. Startet nur unter einer PHP-Version
// nicht, die den Code gar nicht ausführen kann.
require_once __DIR__.'/includes/class-bs-requirements.php';
BS_Requirements::register_notices();
if (! BS_Requirements::can_run(bs_connector_php_version())) {
    BS_Requirements::defer_scheduled_actions();

    return;
}

foreach (['signature', 'delivery-policy', 'log', 'email-verification', 'reconcile', 'settings', 'api-client', 'vat', 'vat-check', 'products', 'accounts', 'checkout', 'orders', 'provision-endpoint', 'privacy', 'admin'] as $bs_connector_file) {
    require_once __DIR__.'/includes/class-bs-'.$bs_connector_file.'.php';
}

// WP-CLI: wp bs-connector reconcile [--days=30], wp bs-connector resync <user-id|email>...
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/includes/class-bs-cli.php';
    WP_CLI::add_command('bs-connector', 'BS_CLI');
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

// Übersetzungen (languages/bs-connector-*.mo; Vorlage languages/bs-connector.pot).
add_action(
    'init',
    static function () {
        load_plugin_textdomain('bs-connector', false, dirname(plugin_basename(BS_CONNECTOR_FILE)).'/languages');
    }
);

add_action(
    'plugins_loaded',
    static function () {
        // Update-Schritte (einmal je Version), auch ohne aktives WooCommerce.
        BS_Settings::maybe_upgrade(BS_CONNECTOR_VERSION);
        // Ohne WooCommerce: Hinweis aus BS_Requirements::register_notices().
        if (! class_exists('WooCommerce')) {
            return;
        }
        BS_Settings::init();
        BS_Products::init();
        BS_Accounts::init();
        BS_Vat_Check::init();
        BS_Checkout::init();
        BS_Orders::init();
        BS_Provision_Endpoint::init();
        BS_Privacy::init();
        if (is_admin()) {
            BS_Admin::init();
        }
    }
);

register_activation_hook(
    __FILE__,
    static function () {
        BS_Requirements::abort_activation(BS_Requirements::current_problems(), BS_CONNECTOR_FILE);
        BS_Settings::maybe_upgrade(BS_CONNECTOR_VERSION);
        BS_Accounts::add_endpoint();
        flush_rewrite_rules();
    }
);
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
