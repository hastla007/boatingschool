<?php

/**
 * Plugin Name:       BoatingSchool Form-Connector
 * Plugin URI:        https://license.example.com/boatingschool-form-connector
 * Description:       Übergibt Einsendungen ausgewählter Form-Captain-Formulare (Demo, Beratung, Infos zum Schulzugang) als Lead an die Lead-Inbox der BoatingSchool-Plattform. Nur zugeordnete, nicht sensible Felder und ein Verweis auf die Einwilligung; die Plattform legt daraus kein Konto an. Braucht Form Captain und einen Lizenzschlüssel. Standardmäßig aus.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Requires Plugins:  form-captain
 * Author:            BoatingSchool
 * Update URI:        https://license.example.com/boatingschool-form-connector
 * Text Domain:       boatingschool-form-connector
 * Domain Path:       /languages
 * License:           Proprietary
 */

declare(strict_types=1);
use BoatingschoolFormConnector\Connector;
use BoatingschoolFormConnector\Kernel\Addon;
use BoatingschoolFormConnector\Privacy;
use FormCaptain\Integrations\ConnectorInterface;

defined('ABSPATH') || exit;

define('BOATINGSCHOOL_FORM_VERSION', '1.1.0');
define('BOATINGSCHOOL_FORM_FILE', __FILE__);

// Eigene Klassen ohne Abhängigkeit vom Kernel (auch für die Tests einzeln ladbar).
require_once __DIR__.'/src/Signature.php';
require_once __DIR__.'/src/Settings.php';
require_once __DIR__.'/src/Client.php';
require_once __DIR__.'/src/CustomerFields.php';
require_once __DIR__.'/src/LeadPayload.php';
require_once __DIR__.'/src/Ledger.php';
require_once __DIR__.'/src/Privacy.php';
require_once __DIR__.'/src/Admin.php';

/**
 * Meldet den Connector bei Form Captain an (Contract v1). Nur aus dem lizenzierten Feature
 * (src/Features/LeadDelivery.php) aufgerufen; ohne Form Captain oder bei anderer Major-Version still.
 */
function boatingschool_form_register_with_form_captain(): void
{
    $boot = static function (): void {
        if (! function_exists('form_captain_contract_version') || ! interface_exists(ConnectorInterface::class)) {
            return;
        }
        if ((int) form_captain_contract_version() !== 1) {
            return; // anderer Major: Form Captain zeigt „Inkompatibel“ nur bei Registrierung, wir bleiben still.
        }
        require_once __DIR__.'/src/Connector.php';
        if (! has_filter('form_captain_connectors_v1', 'boatingschool_form_add_connector')) {
            add_filter('form_captain_connectors_v1', 'boatingschool_form_add_connector');
        }
    };
    if (did_action('form_captain_loaded_v1')) {
        $boot();
    } else {
        add_action('form_captain_loaded_v1', $boot);
    }
}

/**
 * @param  array<string,object>  $connectors
 * @return array<string,object>
 */
function boatingschool_form_add_connector(array $connectors): array
{
    return $connectors + [Connector::KEY => new Connector];
}

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(Privacy::CRON_HOOK);
});

/*
 * Lizenz (BS-11, Entscheidung 17): eigenes Projektprodukt mit Add-on-Kernel und Lizenzclient unter lib/. lib/ steht
 * NICHT im (öffentlichen) Repository, sondern wird beim Bau aus captain-pro erzeugt (bin/build-libs.php, README).
 * Ohne lib/ oder ohne Lizenz meldet sich der Connector nicht bei Form Captain an (fail-closed).
 */
if (is_readable(__DIR__.'/lib/kernel/load.php') && is_readable(__DIR__.'/lib/licensing/load.php')) {
    require_once __DIR__.'/lib/licensing/load.php';
    require_once __DIR__.'/lib/kernel/load.php';
    Addon::autoload('BoatingschoolFormConnector', __DIR__.'/src');
    Addon::run(require __DIR__.'/config.php');
} else {
    add_action('admin_notices', 'boatingschool_form_missing_lib_notice');
}

function boatingschool_form_missing_lib_notice(): void
{
    if (current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>'.esc_html__('BoatingSchool Form-Connector ist inaktiv: Der Ordner „lib“ (Lizenzprüfung) fehlt. Bitte das Release-ZIP einspielen, nicht den Ordner aus dem Repository.', 'boatingschool-form-connector').'</p></div>';
    }
}
