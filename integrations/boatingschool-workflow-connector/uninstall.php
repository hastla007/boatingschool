<?php

/**
 * Deinstallation: nur die eigene Diagnose entfernen. In Workflow Captain
 * bereits erfasste Ereignisse und dessen Freigabe-Option bleiben (sie gehören
 * Workflow Captain); Nonce- und Doppel-Merker laufen als Transients ab.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('bsw_connector_diagnostics');
