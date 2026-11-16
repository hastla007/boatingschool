<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WooCommerce-Plugin: Status-Cache (SEC-P1), Einstellungen nur für
 * Administratoren (SEC-P3) und Geheimnisse standardmäßig nur aus
 * wp-config.php samt Übergang beim Update (BS-04). Die Plugin-Klassen laufen mit einem minimalen
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

    public static function connectionTestAndSecretsNoticeCases(): array
    {
        return [
            'Shop-Manager' => [['manage_woocommerce'], false],
            'Administrator' => [['manage_woocommerce', 'manage_options'], true],
        ];
    }

    #[DataProvider('connectionTestAndSecretsNoticeCases')]
    public function test_connection_test_and_secrets_notice_follow_the_settings_capability(array $caps, bool $visible): void
    {
        $out = $this->harness('settings', [
            'caps' => $caps,
            'stored' => ['bs_connector_settings' => ['secret' => 'aus-db']],
        ]);

        $this->assertSame($visible, $out['connection_test'], 'Knopf „Verbindung testen“');
        $this->assertSame($visible, $out['secrets_notice'], 'Hinweis auf Geheimnisse in der Datenbank');
    }

    public function test_saving_removes_the_database_copy_of_a_secret_set_in_wp_config(): void
    {
        $out = $this->harness('settings', [
            'caps' => ['manage_options'],
            'constants' => ['BS_CONNECTOR_SECRET' => 'aus-wp-config', 'BS_CONNECTOR_APP_URL' => 'https://app.example.de'],
            'stored' => ['bs_connector_settings' => ['secret' => 'alt-aus-db', 'inbound_secret' => 'aus-db', 'app_url' => 'https://alt.example.de', 'allow_db_secrets' => 'yes']],
            'post' => ['allow_db_secrets' => '1', 'secret' => 'neu', 'inbound_secret' => '', 'alert_email' => 'a@example.test'],
        ]);

        $this->assertNotContains('bs_connector_settings[secret]', $out['rendered']);
        $this->assertContains('bs_connector_settings[inbound_secret]', $out['rendered']);
        $this->assertNotContains('secret', $out['stored_after_save'], 'Kopie des Geheimnisses aus wp-config.php gelöscht');
        $this->assertContains('inbound_secret', $out['stored_after_save'], 'Geheimnis ohne Konstante bleibt');
        $this->assertContains('app_url', $out['stored_after_save'], 'andere gesperrte Felder behalten ihren Wert');
        $this->assertSame('aus-db', $out['settings_after_save']['inbound_secret'], 'leeres Feld = unverändert');
    }

    private const ADMIN = ['manage_woocommerce', 'manage_options'];

    private const SECRET_FIELDS = ['bs_connector_settings[secret]', 'bs_connector_settings[inbound_secret]'];

    public function test_new_installation_defaults_to_no_and_ignores_secrets_in_the_database(): void
    {
        $fresh = $this->harness('upgrade', ['caps' => self::ADMIN]);

        $this->assertFalse($fresh['allow_db_secrets'], 'Neuinstallation: nein');
        $this->assertSame('1.4.0', $fresh['version']);
        $this->assertArrayNotHasKey('allow_db_secrets', $fresh['settings']);
        $this->assertFalse($fresh['kept_flag']);
        $this->assertSame('', $fresh['notice']);
        // Formular: keine Geheimnis-Felder, stattdessen Anleitung für wp-config.php.
        $this->assertSame([], array_intersect(self::SECRET_FIELDS, $fresh['rendered']));
        $this->assertContains('bs_connector_secrets_constants', $fresh['rendered']);
        $this->assertStringContainsString("define( 'BS_CONNECTOR_SECRET', '…' );", $fresh['rendered_texts']);
        $this->assertStringContainsString("define( 'BS_CONNECTOR_INBOUND_SECRET', '…' );", $fresh['rendered_texts']);
        $this->assertContains('bs_connector_settings[allow_db_secrets]', $fresh['rendered']);

        // Später (Update-Schritt schon gelaufen) gelangt ein Geheimnis in die Datenbank: ignoriert.
        $later = $this->harness('upgrade', [
            'caps' => self::ADMIN,
            'stored' => ['bs_connector_version' => '1.4.0', 'bs_connector_settings' => ['secret' => 'aus-db', 'inbound_secret' => 'auch-aus-db']],
        ]);
        $this->assertFalse($later['allow_db_secrets']);
        $this->assertSame('', $later['secret']);
        $this->assertSame('', $later['inbound_secret']);
        $this->assertArrayNotHasKey('allow_db_secrets', $later['settings'], 'kein Update-Schritt bei gleicher Version');
        $this->assertStringContainsString('werden ignoriert', $later['notice']);
    }

    public function test_upgrade_keeps_secrets_from_the_database_working_and_shows_a_notice(): void
    {
        $out = $this->harness('upgrade', [
            'caps' => self::ADMIN,
            'stored' => ['bs_connector_settings' => ['app_url' => 'https://app.example.de', 'secret' => 'aus-db', 'inbound_secret' => 'auch-aus-db']],
        ]);

        $this->assertSame('yes', $out['settings']['allow_db_secrets'], 'einmalig ausdrücklich eingeschaltet');
        $this->assertTrue($out['settings_unchanged_by_second_run']);
        $this->assertTrue($out['allow_db_secrets']);
        $this->assertSame('aus-db', $out['secret'], 'Anbindung fällt nicht aus');
        $this->assertSame('auch-aus-db', $out['inbound_secret']);
        $this->assertSame('yes', $out['kept_flag']);
        $this->assertStringContainsString('beim Update', $out['notice']);
        $this->assertStringContainsString('in wp-config.php verschieben und danach', $out['notice']);
        $this->assertSame(self::SECRET_FIELDS, array_values(array_intersect(self::SECRET_FIELDS, $out['rendered'])));

        // Nur für Administratoren.
        $manager = $this->harness('upgrade', [
            'caps' => ['manage_woocommerce'],
            'stored' => ['bs_connector_settings' => ['secret' => 'aus-db']],
        ]);
        $this->assertSame('', $manager['notice']);

        // Ein Geheimnis ohne Konstante genügt (das andere steht in wp-config.php).
        $mixed = $this->harness('upgrade', [
            'caps' => self::ADMIN,
            'constants' => ['BS_CONNECTOR_SECRET' => 'aus-wp-config'],
            'stored' => ['bs_connector_settings' => ['secret' => 'alt', 'inbound_secret' => 'aus-db']],
        ]);
        $this->assertTrue($mixed['allow_db_secrets']);
        $this->assertSame('aus-wp-config', $mixed['secret']);
        $this->assertSame('aus-db', $mixed['inbound_secret']);
    }

    public static function upgradeWithoutDatabaseSecretsCases(): array
    {
        return [
            'nur Adresse gespeichert' => [[], ['app_url' => 'https://app.example.de']],
            'altes Standard-„ja“ gespeichert' => [[], ['app_url' => 'https://app.example.de', 'allow_db_secrets' => 'yes', 'secret' => '']],
            'Geheimnis auch in wp-config.php' => [['BS_CONNECTOR_SECRET' => 'aus-wp-config'], ['secret' => 'alt-aus-db']],
            'ausdrücklich nein' => [[], ['secret' => 'aus-db', 'allow_db_secrets' => 'no']],
        ];
    }

    #[DataProvider('upgradeWithoutDatabaseSecretsCases')]
    public function test_upgrade_without_secrets_needed_from_the_database_gets_no(array $constants, array $settings): void
    {
        $out = $this->harness('upgrade', ['caps' => self::ADMIN, 'constants' => $constants, 'stored' => ['bs_connector_settings' => $settings]]);

        $this->assertFalse($out['allow_db_secrets']);
        $this->assertNotSame('yes', $out['settings']['allow_db_secrets'] ?? null);
        $this->assertFalse($out['kept_flag']);
        $this->assertStringNotContainsString('beim Update', $out['notice']);
        $this->assertSame('1.4.0', $out['version']);
    }

    public function test_explicit_yes_uses_the_database_secret_and_the_constant_always_wins(): void
    {
        $current = ['bs_connector_version' => '1.4.0'];
        $yes = $this->harness('upgrade', ['caps' => self::ADMIN, 'stored' => $current + ['bs_connector_settings' => ['allow_db_secrets' => 'yes', 'secret' => 'aus-db']]]);
        $this->assertTrue($yes['allow_db_secrets']);
        $this->assertSame('aus-db', $yes['secret']);
        $this->assertFalse($yes['kept_flag'], 'kein Update-Hinweis ohne Update-Schritt');
        $this->assertStringNotContainsString('beim Update', $yes['notice']);

        foreach (['yes', 'no'] as $allow) {
            $constant = $this->harness('upgrade', [
                'caps' => self::ADMIN,
                'constants' => ['BS_CONNECTOR_SECRET' => 'aus-wp-config', 'BS_CONNECTOR_INBOUND_SECRET' => 'auch-aus-wp-config'],
                'stored' => $current + ['bs_connector_settings' => ['allow_db_secrets' => $allow, 'secret' => 'aus-db', 'inbound_secret' => 'auch-aus-db']],
            ]);
            $this->assertSame('aus-wp-config', $constant['secret'], "Konstante vor Datenbank (erlauben = {$allow})");
            $this->assertSame('auch-aus-wp-config', $constant['inbound_secret']);
            $this->assertNotContains('bs_connector_secrets_constants', $constant['rendered'], 'keine Anleitung, wenn beide Konstanten gesetzt sind');
        }
    }

    public function test_filter_overrides_the_setting(): void
    {
        $on = $this->harness('upgrade', [
            'caps' => self::ADMIN,
            'filters' => ['bs_connector_allow_db_secrets' => true],
            'stored' => ['bs_connector_version' => '1.4.0', 'bs_connector_settings' => ['secret' => 'aus-db']],
        ]);
        $this->assertTrue($on['allow_db_secrets']);
        $this->assertSame('aus-db', $on['secret']);

        $off = $this->harness('upgrade', [
            'caps' => self::ADMIN,
            'filters' => ['bs_connector_allow_db_secrets' => false],
            'stored' => ['bs_connector_settings' => ['allow_db_secrets' => 'yes', 'secret' => 'aus-db']],
        ]);
        $this->assertFalse($off['allow_db_secrets']);
        $this->assertSame('', $off['secret']);
        $this->assertSame([], array_intersect(self::SECRET_FIELDS, $off['rendered']));
    }

    public function test_saving_with_no_never_stores_a_secret(): void
    {
        // Neuinstallation: Kästchen aus, Geheimnisse in einer selbst gebauten Anfrage.
        $fresh = $this->harness('settings', [
            'caps' => self::ADMIN,
            'post' => ['secret' => 'neu', 'inbound_secret' => 'auch-neu', 'alert_email' => 'a@example.test'],
        ]);
        $this->assertSame([], array_intersect(self::SECRET_FIELDS, $fresh['rendered']));
        $this->assertSame([], array_intersect(self::SECRET_FIELDS, $fresh['saved']));
        $this->assertArrayNotHasKey('secret', $fresh['settings_after_save']);
        $this->assertArrayNotHasKey('inbound_secret', $fresh['settings_after_save']);
        $this->assertSame('no', $fresh['settings_after_save']['allow_db_secrets']);

        // Bisher erlaubt, jetzt ausgeschaltet: kein neues Geheimnis, Update-Hinweis beendet.
        $off = $this->harness('settings', [
            'caps' => self::ADMIN,
            'stored' => ['bs_connector_db_secrets_kept' => 'yes', 'bs_connector_settings' => ['allow_db_secrets' => 'yes', 'secret' => 'alt']],
            'post' => ['secret' => 'neu', 'alert_email' => 'a@example.test'],
        ]);
        $this->assertSame('alt', $off['settings_after_save']['secret'], 'nicht überschrieben');
        $this->assertSame('no', $off['settings_after_save']['allow_db_secrets']);
        $this->assertNotContains('bs_connector_db_secrets_kept', $off['options_after_save']);

        // Filter false: auch ein angehaktes Kästchen speichert kein Geheimnis.
        $filtered = $this->harness('settings', [
            'caps' => self::ADMIN,
            'filters' => ['bs_connector_allow_db_secrets' => false],
            'post' => ['allow_db_secrets' => '1', 'secret' => 'neu'],
        ]);
        $this->assertArrayNotHasKey('secret', $filtered['settings_after_save']);

        // Gegenprobe: ausdrücklich ja speichert das Geheimnis.
        $yes = $this->harness('settings', [
            'caps' => self::ADMIN,
            'post' => ['allow_db_secrets' => '1', 'secret' => 'neu', 'inbound_secret' => ''],
        ]);
        $this->assertSame('neu', $yes['settings_after_save']['secret']);
        $this->assertSame('yes', $yes['settings_after_save']['allow_db_secrets']);
    }

    /** Registrierter Kunde 7 mit den abgeglichenen Feldern. */
    private const CUSTOMER = ['_bs_registered' => '1', 'first_name' => 'Kim', 'billing_company' => 'Segelschule Möwe', 'billing_country' => 'DE'];

    public function test_account_sync_is_only_sent_when_synced_fields_change(): void
    {
        $out = $this->harness('account_sync', ['user_meta' => self::CUSTOMER, 'responses' => [200, 200, 200, 200, 200]]);

        $this->assertSame(1, $out['calls_first_save']);
        $this->assertNotSame('', $out['hash_after_first'], 'Prüfsumme nach erfolgreichem Abgleich');
        $this->assertSame(1, $out['calls_unchanged_save'], 'erneutes Speichern ohne Änderung (z. B. Bestellung): kein PATCH');
        $this->assertSame(1, $out['calls_unsynced_field'], 'nicht abgeglichenes Feld: kein PATCH');
        $this->assertSame(2, $out['calls_synced_field'], 'abgeglichenes Feld geändert: PATCH');
        $this->assertTrue($out['hash_changed']);
        $this->assertSame(3, $out['calls_vat_added']);
        $this->assertSame(3, $out['calls_vat_same_normalized'], 'gleich aufbereitete USt-IdNr.: kein PATCH');
        $this->assertSame(['PATCH /accounts/7', 'PATCH /accounts/7', 'PATCH /accounts/7'], $out['api_calls']);
    }

    public function test_billing_changes_saved_after_profile_update_are_still_synced(): void
    {
        $out = $this->harness('account_sync_wc_save', ['user_meta' => self::CUSTOMER, 'responses' => [200, 200]]);

        $this->assertSame(1, $out['calls_first_save']);
        $this->assertSame(2, $out['calls_after_order'], 'geänderte Firma an der Kasse wird abgeglichen');
        $this->assertEqualsCanonicalizing(['woocommerce_customer_save_address', 'profile_update', 'woocommerce_update_customer'], $out['update_hooks']);
    }

    public function test_failed_account_sync_keeps_the_hash_unset_and_the_retry_sends(): void
    {
        $out = $this->harness('account_sync_failure', ['user_meta' => self::CUSTOMER, 'action_scheduler' => true, 'responses' => [500, 200, 200]]);

        $this->assertSame(1, $out['queued'], 'über Action Scheduler eingeplant');
        $this->assertSame(1, $out['calls_after_failure']);
        $this->assertSame('', $out['hash_after_failure'], 'nach Fehler keine Prüfsumme');
        $this->assertSame([], $out['alerts_after_failure'], 'erst nach dem dritten Versuch Alarm');
        $this->assertSame([['bs_connector_sync_account', ['update', 7, 2]]], $out['retry_queued'], 'Wiederholung wie bisher');
        $this->assertSame(2, $out['queued_after_failure_save'], 'Speichern nach Fehler plant wieder ein');
        $this->assertSame(2, $out['calls_after_retry'], 'Wiederholung sendet, die doppelte Aufgabe entfällt');
        $this->assertTrue($out['hash_after_retry']);
        $this->assertSame(0, $out['queued_after_success_save']);
    }

    public function test_duplicate_queued_updates_and_registration_send_only_once(): void
    {
        $out = $this->harness('account_sync_twice_queued', ['user_meta' => self::CUSTOMER, 'action_scheduler' => true, 'responses' => [200, 201, 200]]);

        $this->assertSame(2, $out['queued']);
        $this->assertSame(1, $out['calls'], 'zweite Aufgabe mit denselben Daten sendet nicht');
        $this->assertSame(0, $out['queued_after_register'], 'Registrierung setzt die Prüfsumme');
        $this->assertSame(['PATCH /accounts/7', 'POST /accounts'], $out['api_calls']);
    }

    public function test_uninstall_removes_the_account_sync_hash(): void
    {
        $out = $this->harness('uninstall');

        $this->assertContains(['user', 0, '_bs_account_sync_hash', true], $out['deleted_meta']);
    }
}
