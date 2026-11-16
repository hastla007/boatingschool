<?php

/**
 * Plugin Name:       BoatingSchool Workflow Connector
 * Description:       Verbindet die Bootsschul-Plattform mit Workflow Captain: Ereignisse der Plattform (Shopkonten, Provisionierung, Code-Batches) als Auslöser und begrenzte Befehle an die Plattform. Eigenes Projektprodukt mit eigener Lizenz.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Text Domain:       boatingschool-workflow-connector
 * Domain Path:       /languages
 * License:           Proprietary
 */
defined('ABSPATH') || exit;

define('BSW_CONNECTOR_VERSION', '0.1.0');
define('BSW_CONNECTOR_FILE', __FILE__);

foreach (['signature', 'manifest', 'payload-mapper', 'settings', 'license', 'diagnostics', 'app-client', 'workflow', 'receiver', 'admin'] as $bsw_connector_file) {
    require_once __DIR__.'/includes/class-bsw-'.$bsw_connector_file.'.php';
}

/*
 * Ohne Workflow Captain feuern dessen Hooks nie: das Plugin bleibt dann
 * wirkungslos und die Statusseite sagt es. Workflow Captain Pro wird nicht
 * vorausgesetzt (öffentlicher Connector-Vertrag des freien Kerns).
 * Zustellung, Wiederholung und Sperre bezahlter Codes bleiben beim
 * WooCommerce-Connector (integrations/woocommerce-connector).
 */
BSW_Workflow::init();
BSW_Receiver::init();

add_action(
    'init',
    static function () {
        load_plugin_textdomain('boatingschool-workflow-connector', false, dirname(plugin_basename(BSW_CONNECTOR_FILE)).'/languages');
    }
);

if (is_admin()) {
    BSW_Admin::init();
}
