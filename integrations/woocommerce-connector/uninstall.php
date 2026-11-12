<?php

/**
 * Deinstallation: nur Einstellungen und Zwischenspeicher entfernen.
 * Bestell- und Kundendaten (Zustellstatus, Verknüpfung zur App) bleiben
 * erhalten -- sie gehören zur Buchhaltung und zur Historie der Bestellungen.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('bs_connector_settings');
delete_transient('bs_connector_catalog');
