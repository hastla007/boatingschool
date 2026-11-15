<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WooCommerce-Plugin: Status-Cache (SEC-P1) und Einstellungen nur für
 * Administratoren (SEC-P3). Die Plugin-Klassen laufen mit einem minimalen
 * WordPress-Ersatz in einem eigenen Prozess (connector-wp-harness.php) --
 * kein Test gegen echtes WordPress.
 */
class ConnectorAccountsAndSettingsTest extends TestCase
{
    private function harness(string $scenario, array $options = []): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/connector-wp-harness.php', $scenario, json_encode($options)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_failed_status_lookups_are_cached_and_fresh_lookups_are_throttled_per_customer(): void
    {
        $out = $this->harness('status', ['responses' => [404, 200, 200, null]]);

        $this->assertNull($out['first']);
        $this->assertNull($out['again']);
        $this->assertSame(1, $out['calls_after_negative'], '404 wird 60 s gemerkt');
        $this->assertTrue($out['checkout']);
        $this->assertSame(2, $out['calls_after_checkout']);
        $this->assertTrue($out['checkout_again']);
        $this->assertSame(2, $out['calls_after_checkout_again'], 'frisch höchstens alle 10 s je Kunde');
        $this->assertSame(3, $out['calls_after_interval']);
        $this->assertNull($out['checkout_network_error']);
        $this->assertNull($out['checkout_network_error_again'], 'Netzfehler sperrt weiter den Einkauf');
        $this->assertSame(4, $out['calls_end']);
    }

    public function test_account_page_reloads_use_the_cache_and_load_fresh_only_after_a_link_attempt(): void
    {
        $out = $this->harness('render_endpoint', ['responses' => [200, 200]]);

        $this->assertSame(1, $out['calls_after_reloads']);
        $this->assertTrue($out['shows_school']);
        $this->assertSame(2, $out['calls_after_link']);
    }

    public function test_settings_are_only_for_administrators(): void
    {
        $out = $this->harness('settings', ['caps' => ['manage_woocommerce']]);

        $this->assertSame(['general'], $out['tabs'], 'kein Tab für Shop-Manager');
        $this->assertNull($out['rendered']);
        $this->assertNull($out['saved'], 'Speichern ohne manage_options wirkungslos');

        $admin = $this->harness('settings', ['caps' => ['manage_woocommerce', 'manage_options']]);
        $this->assertSame(['general', 'bs_connector'], $admin['tabs']);
        $this->assertContains('bs_connector_settings[app_url]', $admin['rendered']);
        $this->assertContains('bs_connector_settings[app_url]', $admin['saved']);
    }

    public function test_fields_set_in_wp_config_are_neither_shown_nor_saved(): void
    {
        $out = $this->harness('settings', [
            'caps' => ['manage_options'],
            'constants' => ['BS_CONNECTOR_APP_URL' => 'https://app.example.de', 'BS_CONNECTOR_SECRET' => 'aus-wp-config'],
        ]);

        $this->assertContains('bs_connector_locked', $out['rendered'], 'Hinweis auf wp-config.php');
        $this->assertNotContains('bs_connector_settings[app_url]', $out['rendered']);
        $this->assertNotContains('bs_connector_settings[secret]', $out['rendered']);
        $this->assertContains('bs_connector_settings[key_id]', $out['rendered']);
        $this->assertNotContains('bs_connector_settings[app_url]', $out['saved']);
        $this->assertNotContains('bs_connector_settings[secret]', $out['saved']);
        $this->assertSame(['alert_email' => 'a@example.test'], $out['post_after_save']);
    }
}
