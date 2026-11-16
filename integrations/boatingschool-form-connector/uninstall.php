<?php

use BoatingschoolFormConnector\Kernel\Addon;

/**
 * Deinstallation: eigene Einstellungen, Merkbuch und offene Löschungen entfernen; Lizenzdaten über den Kernel
 * (falls lib/ vorhanden). Leads in der Plattform bleiben unberührt (dort gelten Inbox und Aufbewahrungsfrist).
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['boatingschool_form_settings', 'boatingschool_form_ledger', 'boatingschool_form_pending_deletions'] as $boatingschool_form_option) {
    delete_option($boatingschool_form_option);
}
wp_clear_scheduled_hook('boatingschool_form_retry_deletions');

if (is_readable(__DIR__.'/lib/kernel/load.php') && is_readable(__DIR__.'/lib/licensing/load.php')) {
    require_once __DIR__.'/lib/licensing/load.php';
    require_once __DIR__.'/lib/kernel/load.php';
    Addon::autoload('BoatingschoolFormConnector', __DIR__.'/src');
    Addon::uninstall(require __DIR__.'/config.php');
}
