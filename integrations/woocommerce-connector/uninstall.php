<?php

/**
 * Deinstallation: nur Einstellungen und Zwischenspeicher entfernen.
 * Bestell- und Kundendaten (Zustellstatus, Verknüpfung zur App) bleiben
 * erhalten -- sie gehören zur Buchhaltung und zur Historie der Bestellungen.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('bs_connector_settings');
delete_transient('bs_connector_catalog');

// Ausstehende Hintergrund-Aufgaben des Plugins entfernen.
if (function_exists('as_unschedule_all_actions')) {
    foreach (['bs_connector_deliver_item', 'bs_connector_void_item', 'bs_connector_sync_account'] as $bs_connector_hook) {
        as_unschedule_all_actions($bs_connector_hook, null, 'bs-connector');
    }
}

// Zwischengespeicherte Kontostatus (Transients bs_connector_status_*).
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bs\\_connector\\_status\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bs\\_connector\\_status\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
