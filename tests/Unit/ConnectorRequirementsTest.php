<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WooCommerce-Plugin, Mindestanforderungen (MINVER-1 vom 17.11.2026):
 * PHP 8.5, WordPress 7.0, WooCommerce 11.2 sperren die Aktivierung, im
 * Betrieb nur ein Hinweis; MySQL 9.7 bzw. MariaDB 12.3 nur als Hinweis.
 * Läuft mit dem WordPress-Ersatz connector-wp-harness.php (eigener Prozess),
 * die Versionen kommen aus den Optionen.
 */
class ConnectorRequirementsTest extends TestCase
{
    private const CURRENT = ['php' => '8.5.1', 'wordpress' => '7.1.4'];

    private static string $pluginDir;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = dirname(__DIR__, 2).'/integrations/woocommerce-connector';
    }

    private function harness(string $scenario, array $options = []): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/connector-wp-harness.php', $scenario, json_encode($options)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function requirements(array $env, string $wc = '11.3.0', string $db = '9.7.0'): array
    {
        return $this->harness('requirements', [
            'env' => $env + self::CURRENT,
            'constants' => $wc === '' ? [] : ['WC_VERSION' => $wc],
            'woocommerce' => $wc !== '',
            'caps' => ['manage_options'],
            'db' => $db,
        ]);
    }

    public function test_current_versions_activate_and_run_without_notice(): void
    {
        $out = $this->requirements([]);

        $this->assertSame([], $out['problems']);
        $this->assertTrue($out['can_run']);
        $this->assertNull($out['activation_stop'], 'Aktivierung läuft durch');
        $this->assertSame([], $out['deactivated']);
        $this->assertSame('', $out['admin_notices']);

        // Entwicklerversionen zählen wie die Version selbst.
        $this->assertSame([], $this->requirements(['php' => '8.6.0-dev', 'wordpress' => '7.0'], '11.2.0')['problems']);
    }

    public static function outdatedCases(): array
    {
        return [
            'WordPress 6.9' => [['wordpress' => '6.9.1'], '11.3.0', 'WordPress 7.0 oder neuer (aktuell: 6.9.1)'],
            'WooCommerce 11.1' => [[], '11.1.2', 'WooCommerce 11.2 oder neuer (aktuell: 11.1.2)'],
            'WooCommerce nicht aktiv' => [[], '', 'WooCommerce 11.2 oder neuer (nicht aktiv)'],
            'PHP 8.4' => [['php' => '8.4.15'], '11.3.0', 'PHP 8.5 oder neuer (aktuell: 8.4.15)'],
        ];
    }

    #[DataProvider('outdatedCases')]
    public function test_outdated_wordpress_woocommerce_or_php_blocks_the_activation_with_a_notice(array $env, string $wc, string $problem): void
    {
        $out = $this->requirements($env, $wc);

        $this->assertSame([$problem], $out['problems']);
        $this->assertSame('die:Mindestanforderungen nicht erfüllt', $out['activation_stop'], 'Aktivierung bricht ab');
        $this->assertSame(['woocommerce-connector/boatingschool-connector.php'], $out['deactivated']);
        $this->assertStringContainsString('wurde nicht aktiviert', $out['activation_message']);
        $this->assertStringContainsString($problem, $out['activation_message']);

        // Im Betrieb (z. B. nach einem Downgrade): Hinweis für Administratoren.
        $this->assertStringContainsString('notice-error', $out['admin_notices']);
        $this->assertStringContainsString($problem, $out['admin_notices']);
        $this->assertStringContainsString($wc === '' ? 'ist angehalten' : 'läuft in einer nicht unterstützten Umgebung weiter', $out['admin_notices']);
    }

    public function test_notices_are_only_for_administrators(): void
    {
        $out = $this->harness('requirements', ['env' => ['wordpress' => '6.9'] + self::CURRENT, 'constants' => ['WC_VERSION' => '11.3.0'], 'woocommerce' => true, 'caps' => ['manage_woocommerce'], 'db' => '8.4.13']);

        $this->assertSame('', $out['admin_notices']);
    }

    public static function databaseCases(): array
    {
        return [
            'MySQL 8.4' => ['8.4.13', 'Die Datenbank (MySQL 8.4.13) ist älter als empfohlen (MySQL 9.7 oder neuer).'],
            'MySQL 9.6' => ['9.6.0', 'Die Datenbank (MySQL 9.6.0) ist älter als empfohlen (MySQL 9.7 oder neuer).'],
            'MariaDB 11.4 mit Präfix' => ['5.5.5-11.4.2-MariaDB-ubu2404', 'Die Datenbank (MariaDB 11.4.2) ist älter als empfohlen (MariaDB 12.3 oder neuer).'],
            'MariaDB 12.2' => ['12.2.1-MariaDB', 'Die Datenbank (MariaDB 12.2.1) ist älter als empfohlen (MariaDB 12.3 oder neuer).'],
            'MySQL 9.7' => ['9.7.0', null],
            'MariaDB 12.3' => ['5.5.5-12.3.0-MariaDB', null],
            'unbekannt' => ['', null],
        ];
    }

    /** Eine alte Datenbank ergibt nur einen Hinweis: keine Sperre, Aktivierung läuft. */
    #[DataProvider('databaseCases')]
    public function test_an_old_database_only_gives_a_warning(string $serverInfo, ?string $warning): void
    {
        $out = $this->requirements([], '11.3.0', $serverInfo);

        $this->assertSame([], $out['problems']);
        $this->assertNull($out['activation_stop']);
        $this->assertStringNotContainsString('notice-error', $out['admin_notices']);
        if ($warning === null) {
            $this->assertSame('', $out['admin_notices']);
        } else {
            $this->assertStringContainsString('notice-warning', $out['admin_notices']);
            $this->assertStringContainsString($warning, $out['admin_notices']);
            $this->assertStringContainsString('Der Connector läuft weiter', $out['admin_notices']);
        }
    }

    /** Unterhalb der PHP-Version, die den Code ausführen kann, startet das Plugin nicht. */
    public function test_php_below_the_runtime_floor_stops_the_plugin_with_a_notice(): void
    {
        $out = $this->requirements(['php' => '8.0.30']);

        $this->assertFalse($out['can_run']);
        $this->assertStringContainsString('ist angehalten', $out['admin_notices']);
        $this->assertStringContainsString('Eingeplante Zustellungen bleiben erhalten', $out['admin_notices']);

        $this->assertTrue($this->requirements(['php' => '8.4.15'])['can_run'], 'nach einem Downgrade auf 8.4 läuft das Plugin weiter');
    }

    /** Eingeplante Aufgaben werden nicht verworfen (Action Scheduler ohne Rückruf: fehlgeschlagen), sondern unverändert verschoben. */
    public function test_scheduled_actions_are_deferred_unchanged_while_the_plugin_cannot_start(): void
    {
        $out = $this->harness('requirements_defer', ['action_scheduler' => true]);

        foreach (['bs_connector_deliver_item', 'bs_connector_void_item', 'bs_connector_vat_recheck', 'bs_connector_sync_account'] as $hook) {
            $this->assertContains($hook, $out['hooks']);
        }
        $this->assertSame([['bs_connector_deliver_item', [12, 34, 2]], ['bs_connector_void_item', [12, 34, 1, 'k', 1]]], $out['queue']);
        foreach ($out['queue_meta'] as $meta) {
            $this->assertSame('bs-connector', $meta['group']);
            $this->assertEqualsWithDelta(3600, $meta['delay'], 5);
        }
    }

    /** Die verschobenen Hooks sind genau die, die das Plugin in Action Scheduler einplant. */
    public function test_the_deferred_hooks_match_the_hooks_the_plugin_schedules(): void
    {
        $code = '';
        foreach (glob(self::$pluginDir.'/includes/class-bs-*.php') as $file) {
            if (! str_ends_with($file, 'class-bs-requirements.php')) {
                $code .= file_get_contents($file);
            }
        }
        preg_match_all("/const HOOK_\\w+ = '(bs_connector_[a-z_]+)'/", $code, $constants);
        preg_match_all("/as_(?:enqueue_async|schedule_single)_action\\([^;]*?'(bs_connector_[a-z_]+)'/", $code, $literals);
        $scheduled = array_values(array_unique(array_merge($constants[1], $literals[1])));
        sort($scheduled);

        $requirements = file_get_contents(self::$pluginDir.'/includes/class-bs-requirements.php');
        preg_match('/const SCHEDULED_HOOKS = \\[([^\\]]+)\\]/', $requirements, $match);
        preg_match_all("/'([a-z_]+)'/", $match[1], $deferred);
        $deferred = $deferred[1];
        sort($deferred);

        $this->assertSame($scheduled, $deferred);
    }

    /** Kopfzeilen, readme und Laden der Plugin-Datei passen zu den Mindestversionen. */
    public function test_plugin_headers_and_bootstrap_use_the_minimum_versions(): void
    {
        $main = file_get_contents(self::$pluginDir.'/boatingschool-connector.php');
        $readme = file_get_contents(self::$pluginDir.'/readme.txt');

        foreach (['Requires at least: 7.0', 'Requires PHP:      8.5', 'WC requires at least: 11.2', 'WC tested up to:   11.3', 'Version:           1.5.0'] as $header) {
            $this->assertStringContainsString($header, $main);
        }
        $this->assertStringContainsString("define('BS_CONNECTOR_VERSION', '1.5.0');", $main);
        foreach (['Requires at least: 7.0', 'Tested up to: 7.1', 'Requires PHP: 8.5', 'Stable tag: 1.5.0', '= 1.5.0 ='] as $line) {
            $this->assertStringContainsString($line, $readme);
        }

        // Prüfung vor allen anderen Klassen; Aktivierung bricht vor dem Update-Schritt ab.
        $this->assertLessThan(strpos($main, "foreach (['signature'"), strpos($main, "require_once __DIR__.'/includes/class-bs-requirements.php';"));
        $activation = substr($main, strpos($main, 'register_activation_hook('));
        $this->assertLessThan(strpos($activation, 'BS_Settings::maybe_upgrade'), strpos($activation, 'BS_Requirements::abort_activation(BS_Requirements::current_problems(), BS_CONNECTOR_FILE);'));
    }
}
