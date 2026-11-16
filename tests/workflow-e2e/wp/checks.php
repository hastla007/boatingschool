<?php

/**
 * WordPress-Seite des Workflow-E2E-Tests (tests/workflow-e2e/run.sh), Aufruf mit
 * "wp eval-file checks.php <phase>". Jede Prüfung schreibt eine Zeile ("  ok   …" bzw. "  FAIL …"); Exit ≠ 0 bei
 * einem Fehlschlag. Zusätzliche Angaben kommen über die Umgebung (BSW_E2E_*).
 */

use WorkflowCaptain\Plugin;

$GLOBALS['bsw_e2e_failures'] = 0;
function bsw_e2e_check(bool $ok, string $label, string $detail = ''): void
{
    echo ($ok ? '  ok   ' : '  FAIL ').$label.(! $ok && $detail !== '' ? ' – '.$detail : '')."\n";
    if (! $ok) {
        $GLOBALS['bsw_e2e_failures']++;
    }
}

function bsw_e2e_events(): array
{
    global $wpdb;

    return (array) $wpdb->get_results("SELECT id, type, state, last_error, envelope FROM {$wpdb->prefix}workflow_captain_events WHERE type LIKE 'xboatingschoolconnector.%' ORDER BY id", ARRAY_A);
}

$phase = (string) ($args[0] ?? '');
$admin = get_user_by('login', 'admin');
wp_set_current_user($admin ? (int) $admin->ID : 1);
$status = BSW_Admin::status();
echo "WordPress-Phase {$phase}: Connector {$status['version']}, Workflow Captain ".(defined('WORKFLOW_CAPTAIN_VERSION') ? WORKFLOW_CAPTAIN_VERSION : '-').', Pro '.(defined('WORKFLOW_CAPTAIN_PRO_VERSION') ? WORKFLOW_CAPTAIN_PRO_VERSION : '-')."\n";

switch ($phase) {
    case 'no_pro':
        bsw_e2e_check($status['registration'] === 'workflow_captain_pro_missing' && $status['runtime'] === 'workflow_captain_pro_missing', 'ohne Workflow Captain Pro keine Anmeldung', wp_json_encode([$status['registration'], $status['runtime']]));
        bsw_e2e_check(BSW_Admin::send_test_event(time()) === 'workflow_captain_pro_missing', 'Testereignis meldet workflow_captain_pro_missing');
        ob_start();
        BSW_Admin::notice();
        bsw_e2e_check(str_contains((string) ob_get_clean(), 'Workflow Captain Pro fehlt oder das Recht fehlt'), 'Admin-Hinweis „Workflow Captain Pro fehlt oder das Recht fehlt“');
        break;

    case 'no_right':
        bsw_e2e_check($status['registration'] === 'runtime_not_allowed' && Plugin::instance()->connectors()->reason(BSW_Manifest::KEY) === 'runtime_not_allowed', 'Pro ohne Recht: Workflow Captain lehnt ab (runtime_not_allowed)', wp_json_encode($status['registration']));
        bsw_e2e_check(BSW_Admin::send_test_event(time()) === 'runtime_not_allowed', 'Testereignis meldet runtime_not_allowed');
        ob_start();
        BSW_Admin::render();
        $html = (string) ob_get_clean();
        bsw_e2e_check(str_contains($html, 'Workflow Captain Pro (Laufzeit)') && str_contains($html, 'runtime_not_allowed'), 'Statusseite zeigt die fehlende Laufzeit');
        break;

    case 'setup':
        bsw_e2e_check($status['registration'] === 'accepted' && $status['runtime'] === 'ok', 'Anmeldung angenommen (Pro aktiv, Recht vorhanden)', wp_json_encode([$status['registration'], $status['runtime']]));
        update_option('workflow_captain_connectors', [BSW_Manifest::KEY => ['enabled' => true]]);
        bsw_e2e_check(Plugin::instance()->connectors()->is_enabled(BSW_Manifest::KEY), 'unter „Eigene Connectoren“ freigegeben');
        $wfs = Plugin::instance()->workflows();
        $ids = [];
        foreach (['shop_account.linked' => 'shop_account_linked', 'code_batch.delivered' => 'codes_delivered'] as $type => $template) {
            $graph = ['nodes' => [
                ['id' => 't', 'type' => 'trigger', 'event' => BSW_Manifest::type($type)],
                ['id' => 'a', 'type' => 'action', 'action' => 'xboatingschoolconnector.school_admin.notice', 'config' => ['template' => $template]],
                ['id' => 'e', 'type' => 'end'],
            ], 'edges' => [['from' => 't', 'to' => 'a'], ['from' => 'a', 'to' => 'e']]];
            [$id, $v] = $wfs->save(null, 'E2E Bootsschule '.$type, $graph);
            $pub = $wfs->publish((int) $id, get_current_user_id());
            bsw_e2e_check($v === [] && $pub === [], 'Workflow „'.$type.' → Hinweis '.$template.'“ veröffentlicht', implode(',', array_merge($v, $pub)));
            $ids[] = (int) $id;
        }
        update_option('bsw_e2e_workflows', $ids);
        $test = BSW_Admin::send_test_event(time());
        bsw_e2e_check($test === 'captured', 'Testereignis über Workflow Captain erfasst', $test);
        ob_start();
        BSW_Admin::render();
        $html = (string) ob_get_clean();
        $want = ['Plugin-Version', BSW_CONNECTOR_VERSION, 'Workflow Captain Pro (Laufzeit)', 'Anmeldung in Workflow Captain', 'accepted', 'Payload-Mapping', 'xboatingschoolconnector.code_batch.delivered', 'Zustellung und Fehler', 'Wiederholung'];
        $missing = array_values(array_filter($want, static fn ($w) => ! str_contains($html, $w)));
        bsw_e2e_check($missing === [], 'Statusseite: Version, Laufzeit, Anmeldung, Mapping, Zustellung, Wiederholung', implode(',', $missing));
        $secrets = array_filter([BSW_Settings::get('inbound_secret'), BSW_Settings::get('inbound_secret_next'), BSW_Settings::get('secret')]);
        bsw_e2e_check(count($secrets) === 3 && array_filter($secrets, static fn ($x) => str_contains($html, $x)) === [], 'Statusseite ohne Geheimnisse (alle drei eingerichtet)');
        break;

    case 'captured':
        // BSW_E2E_TYPES: kommagetrennte App-Typen, die erfasst sein müssen; BSW_E2E_FORBIDDEN: JSON-Liste verbotener Werte.
        $events = bsw_e2e_events();
        $types = array_column($events, 'type');
        foreach (array_filter(explode(',', (string) getenv('BSW_E2E_TYPES'))) as $type) {
            bsw_e2e_check(in_array(BSW_Manifest::type($type), $types, true), 'in Workflow Captain erfasst: '.$type);
        }
        $dump = wp_json_encode(array_column($events, 'envelope'));
        $forbidden = json_decode((string) getenv('BSW_E2E_FORBIDDEN'), true) ?: [];
        $leaks = array_values(array_filter($forbidden, static fn ($f) => is_string($f) && $f !== '' && str_contains($dump, $f)));
        bsw_e2e_check($leaks === [] && ! str_contains($dump, '@'), 'keine Codes, Adressen oder Namen in den erfassten Ereignissen ('.count($forbidden).' geprüft)', (string) count($leaks));
        $d = BSW_Diagnostics::get();
        echo '    Diagnose: empfangen '.(int) $d['received'].', erfasst '.(int) $d['captured'].', Doppel '.(int) $d['duplicates'].', abgelehnt '.wp_json_encode($d['refused'])."\n";
        break;

    case 'diagnostics':
        // BSW_E2E_EXPECT: JSON {"duplicates_min":1,"refused":["signature_invalid",...]}
        $d = BSW_Diagnostics::get();
        $expect = json_decode((string) getenv('BSW_E2E_EXPECT'), true) ?: [];
        bsw_e2e_check((int) $d['duplicates'] >= (int) ($expect['duplicates_min'] ?? 0), 'Diagnose zählt Doppel ('.(int) $d['duplicates'].')');
        foreach ((array) ($expect['refused'] ?? []) as $code) {
            bsw_e2e_check(isset($d['refused'][$code]), 'Diagnose zählt Ablehnung '.$code, wp_json_encode($d['refused']));
        }
        bsw_e2e_check(! str_contains(wp_json_encode($d), '@'), 'Diagnose ohne Werte aus Ereignissen');
        break;

    case 'work':
        for ($i = 0; $i < 15; $i++) {
            Plugin::instance()->run_worker();
        }
        global $wpdb;
        $ids = array_map('intval', (array) get_option('bsw_e2e_workflows', []));
        $runs = $ids ? (array) $wpdb->get_results("SELECT workflow_id, state, last_error FROM {$wpdb->prefix}workflow_captain_runs WHERE workflow_id IN (".implode(',', $ids).') ORDER BY id', ARRAY_A) : [];
        $states = array_count_values(array_column($runs, 'state'));
        bsw_e2e_check(($states['succeeded'] ?? 0) >= 2 && count($runs) === ($states['succeeded'] ?? 0), 'Workflow-Läufe erfolgreich (Befehl school_admin.notice an die App)', wp_json_encode($runs));
        $d = BSW_Diagnostics::get();
        bsw_e2e_check((int) $d['commands'] >= 2 && $d['command_errors'] === [], 'Diagnose: Befehle an die App ohne Fehler', wp_json_encode([$d['commands'], $d['command_errors']]));
        break;

    default:
        echo "Phase fehlt.\n";
        exit(2);
}

echo $GLOBALS['bsw_e2e_failures'] ? "WordPress-Phase {$phase}: {$GLOBALS['bsw_e2e_failures']} Fehler\n" : "WordPress-Phase {$phase}: bestanden\n";
exit($GLOBALS['bsw_e2e_failures'] ? 1 : 0);
