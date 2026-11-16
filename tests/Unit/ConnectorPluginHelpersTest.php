<?php

namespace Tests\Unit;

use BS_Email_Verification;
use BS_Log;
use BS_Pot_Extractor;
use BS_Privacy;
use BS_Reconcile;
use PHPUnit\Framework\TestCase;

/**
 * Hilfsklassen des WooCommerce-Plugins ohne WordPress-Abhängigkeit:
 * E-Mail-Bestätigung, Protokoll ohne Codewerte, Abgleich (WP-CLI),
 * Datenschutz-Export und die Übersetzungsvorlage.
 */
class ConnectorPluginHelpersTest extends TestCase
{
    private static string $pluginDir;

    public static function setUpBeforeClass(): void
    {
        if (! defined('BS_CONNECTOR_TESTING')) {
            define('BS_CONNECTOR_TESTING', true);
        }
        self::$pluginDir = dirname(__DIR__, 2).'/integrations/woocommerce-connector';
        foreach (['log', 'email-verification', 'reconcile', 'privacy'] as $file) {
            require_once self::$pluginDir.'/includes/class-bs-'.$file.'.php';
        }
        require_once self::$pluginDir.'/bin/make-pot.php';
    }

    public function test_email_verification_links_are_signed_expire_and_are_single_use(): void
    {
        $now = 1_700_000_000;
        $expires = $now + BS_Email_Verification::TTL;
        $token = BS_Email_Verification::token('geheim', 42, 'Kim@Example.test', 'einmal-1', $expires);

        $this->assertTrue(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', 'einmal-1', $expires, $token, $now), 'Groß-/Kleinschreibung der Adresse egal');
        $this->assertTrue(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', 'einmal-1', $expires, strtoupper($token), $now));
        $this->assertFalse(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', 'einmal-1', $expires, $token, $expires + 1), 'abgelaufen');
        $this->assertFalse(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', 'einmal-1', $expires + 3600, $token, $now), 'verlängerter Ablauf');
        $this->assertFalse(BS_Email_Verification::verify('geheim', 43, 'kim@example.test', 'einmal-1', $expires, $token, $now), 'anderer Kunde');
        $this->assertFalse(BS_Email_Verification::verify('geheim', 42, 'andere@example.test', 'einmal-1', $expires, $token, $now), 'geänderte Adresse');
        $this->assertFalse(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', 'einmal-2', $expires, $token, $now), 'neuer Link macht den alten ungültig');
        $this->assertFalse(BS_Email_Verification::verify('geheim', 42, 'kim@example.test', '', $expires, $token, $now), 'nach Verwendung gelöschter Schlüssel');
        $this->assertFalse(BS_Email_Verification::verify('anderes', 42, 'kim@example.test', 'einmal-1', $expires, $token, $now));
        $this->assertFalse(BS_Email_Verification::verify('', 42, 'kim@example.test', 'einmal-1', $expires, $token, $now));
    }

    public function test_confirmation_state_and_resend_interval(): void
    {
        $this->assertFalse(BS_Email_Verification::is_confirmed([], 'kim@example.test'));
        $this->assertTrue(BS_Email_Verification::is_confirmed(['_bs_email_confirmed' => 'kim@example.test'], 'Kim@Example.test'));
        $this->assertFalse(BS_Email_Verification::is_confirmed(['_bs_email_confirmed' => 'alt@example.test'], 'kim@example.test'), 'geänderte Adresse ist wieder unbestätigt');
        $this->assertTrue(BS_Email_Verification::is_confirmed(['_bs_provisioned' => '1'], 'kim@example.test'), 'von der Plattform angelegt');

        $this->assertFalse(BS_Email_Verification::can_resend(1000, 1030));
        $this->assertTrue(BS_Email_Verification::can_resend(1000, 1060));
        $this->assertTrue(BS_Email_Verification::can_resend(0, 1000));
    }

    public function test_log_context_never_contains_code_values_or_secrets(): void
    {
        $context = BS_Log::redact([
            'order_id' => 17,
            'link_code' => 'ABCD-EFGH',
            'code' => 'XXXX-YYYY-ZZZZ-WWWW',
            'codes' => ['XXXX-YYYY-ZZZZ-WWWW'],
            'coupon_code' => 'X',
            'course_code' => 'SRC',
            'product_code' => 'FAHRSTUNDE-1-EH',
            'error_code' => 'LINK_CODE_INVALID',
            'secret' => 's',
            'inbound_secret' => 's',
            'bs_token' => 't',
            'X-BS-Signature' => 'sig',
            'headers' => ['Authorization' => 'Bearer x', 'Accept' => 'application/json'],
            'error' => 'HTTP 500',
        ]);

        $this->assertSame([
            'order_id' => 17,
            'course_code' => 'SRC',
            'product_code' => 'FAHRSTUNDE-1-EH',
            'error_code' => 'LINK_CODE_INVALID',
            'headers' => ['Accept' => 'application/json'],
            'error' => 'HTTP 500',
        ], $context);
    }

    public function test_reconcile_reports_mismatches_between_shop_and_platform(): void
    {
        $delivered = ['status' => 'delivered', 'batch_id' => 'b-1', 'quantity' => 5, 'order_cancelled' => false];
        $remote = ['batch_id' => 'b-1', 'quantity' => 5, 'status' => 'delivered', 'open' => 5];

        $this->assertSame([], BS_Reconcile::compare($delivered, 200, $remote));
        $this->assertSame(['Im Shop zugestellt, auf der Plattform nicht gefunden'], BS_Reconcile::compare($delivered, 404, null));
        $this->assertSame([], BS_Reconcile::compare(['status' => 'cancelled'] + $delivered, 404, null), 'storniert vor der Lieferung');
        $this->assertCount(1, BS_Reconcile::compare($delivered, 200, ['batch_id' => 'b-2'] + $remote));
        $this->assertSame(['Abweichende Menge (Shop 5, Plattform 4)'], BS_Reconcile::compare($delivered, 200, ['quantity' => 4] + $remote));
        $this->assertSame(['Im Shop „failed“, auf der Plattform geliefert'], BS_Reconcile::compare(['status' => 'failed', 'batch_id' => ''] + $delivered, 200, $remote));
        $this->assertSame(['Bestellung storniert/erstattet, aber 2 Code(s) auf der Plattform noch offen'],
            BS_Reconcile::compare(['order_cancelled' => true] + $delivered, 200, ['open' => 2] + $remote));
        $this->assertSame([], BS_Reconcile::compare(['order_cancelled' => true] + $delivered, 200, ['open' => 0, 'status' => 'voided'] + $remote));
        $this->assertStringContainsString('nicht erreichbar', BS_Reconcile::compare($delivered, null, null)[0]);
        $this->assertStringContainsString('503', BS_Reconcile::compare($delivered, 503, null)[0]);
    }

    public function test_privacy_export_covers_the_plugin_user_meta(): void
    {
        $rows = BS_Privacy::rows([
            'bs_app_user_id' => 'u-1',
            'bs_app_tenant_id' => 't-1',
            '_bs_registered' => '1',
            '_bs_provisioned' => '',
            '_bs_email_confirmed' => 'kim@example.test',
            '_bs_email_verify_sent' => '1700000000',
            '_bs_email_verify_key' => 'geheimer-einmalschluessel',
        ]);

        $this->assertSame([
            ['name' => 'App-Konto (Plattform)', 'value' => 'u-1'],
            ['name' => 'Bootsschule (Plattform)', 'value' => 't-1'],
            ['name' => 'Bei der Plattform angemeldet', 'value' => 'ja'],
            ['name' => 'Bestätigte E-Mail-Adresse', 'value' => 'kim@example.test'],
            ['name' => 'Bestätigungsmail gesendet am', 'value' => '2023-11-14 22:13 UTC'],
            ['name' => 'Offener Bestätigungslink', 'value' => 'ja'],
        ], $rows);
        $this->assertStringNotContainsString('geheimer-einmalschluessel', json_encode($rows));
        $this->assertSame([], BS_Privacy::rows([]));

        // Jede Meta, die das Plugin am Nutzer setzt, wird exportiert und gelöscht.
        $code = '';
        foreach (glob(self::$pluginDir.'/includes/*.php') as $file) {
            $code .= file_get_contents($file);
        }
        preg_match_all("/update_user_meta\\([^,]+,\\s*'((?:bs_|_bs_)[a-z_]+)'/", $code, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $key) {
            $this->assertArrayHasKey($key, BS_Privacy::META_KEYS, "Nutzer-Meta {$key} fehlt im Datenschutz-Export");
        }
    }

    public function test_translation_template_is_up_to_date(): void
    {
        $pot = (string) file_get_contents(self::$pluginDir.'/languages/bs-connector.pot');
        $this->assertSame(BS_Pot_Extractor::render(self::$pluginDir), $pot, 'languages/bs-connector.pot neu erzeugen: php bin/make-pot.php');

        $entries = BS_Pot_Extractor::extract(self::$pluginDir);
        $msgids = array_column($entries, 'msgid');
        $this->assertContains('Verbindung testen', $msgids);
        $this->assertContains('Bestätigungsmail erneut senden', $msgids);
        $this->assertContains('%d Position wird erneut zugestellt.', $msgids);
        $plural = current(array_filter($entries, fn ($e) => $e['msgid'] === '%d Position wird erneut zugestellt.'));
        $this->assertSame('%d Positionen werden erneut zugestellt.', $plural['plural']);
    }

    public function test_pot_extractor_reads_literals_comments_and_escapes(): void
    {
        $found = BS_Pot_Extractor::extract_file(<<<'PHP'
<?php
echo sprintf( /* translators: %s: Name */ __('Hallo %s', 'bs-connector'), $x);
echo __("Zeile 1\nZeile \$2", 'bs-connector');
echo __('Fremde Domain', 'woocommerce');
echo __($variable, 'bs-connector');
echo esc_html_e('It\'s', 'bs-connector');
$obj->__('Methode', 'bs-connector');
PHP);
        $this->assertSame(['Hallo %s', "Zeile 1\nZeile \$2", "It's"], array_column($found, 'msgid'));
        $this->assertSame('translators: %s: Name', $found[0]['comment']);
        $this->assertNull($found[1]['comment']);
        $this->assertSame("\"\"\n\"Zeile 1\\n\"\n\"Zeile \$2\"", BS_Pot_Extractor::po_string("Zeile 1\nZeile \$2"));
        $this->assertSame('"Sag \"Hallo\""', BS_Pot_Extractor::po_string('Sag "Hallo"'));
    }
}
