<?php

/**
 * Plugin Name: BoatingSchool Workflow Connector – E2E test site (nur Testsite, nie ausliefern)
 *
 * Kopie nach wp-content/mu-plugins einer Wegwerf-Testsite (tests/workflow-e2e/run.sh). Steht für zwei Lizenzen
 * ein, die eine Testsite nicht hat, gesteuert über die Option bsw_e2e (Array; ohne sie wird nichts ersetzt):
 * - right:   Workflow Captain Pro gewährt workflow.custom_connectors (Filter
 *            workflow_captain_connector_runtime_allowed_v1 nach dem Callback von Pro). Mit right=false bleibt der
 *            Filter unberührt; ein echtes Workflow Captain Pro ohne Lizenz lehnt dann ab (runtime_not_allowed).
 * - license: eigene Lizenz des Connectors (lib/license-client.php liegt nicht im Repository).
 * Dazu slow=true: der Ereignis-Endpunkt antwortet erst nach 12 Sekunden (Timeout der App nach erfolgreichem Aufruf).
 */
defined('ABSPATH') || exit;

/** Ohne die Option (setzt run.sh) wird nichts ersetzt: kein Recht, keine Lizenz. */
function bsw_e2e_flag(string $name): bool
{
    $flags = get_option('bsw_e2e', []);

    return is_array($flags) && ! empty($flags[$name]);
}

add_filter(
    'workflow_captain_connector_runtime_allowed_v1',
    static fn ($allowed, $key = '') => bsw_e2e_flag('right') && defined('WORKFLOW_CAPTAIN_PRO_VERSION') ? true : $allowed,
    99,
    2
);

add_action('plugins_loaded', static function () {
    if (class_exists('BSW_License') && is_array(get_option('bsw_e2e'))) {
        BSW_License::use_checker(static fn () => bsw_e2e_flag('license') ? BSW_License::VALID : BSW_License::INVALID);
    }
});

add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    if (bsw_e2e_flag('slow') && str_starts_with((string) $request->get_route(), '/boatingschool-workflow/v1/events')) {
        sleep(12);
    }

    return $response;
}, 10, 3);
