<?php

namespace Tests\Unit;

use BS_Settings;
use BS_Vat;
use PHPUnit\Framework\TestCase;

/**
 * WordPress-freie Logik des WooCommerce-Plugins: USt-IdNr.-Prüfung (Format je
 * EU-Land, Auswertung der VIES-Antworten) und die Auflösung der Geheimnisse
 * (wp-config.php vor Datenbank).
 */
class ConnectorVatCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('BS_CONNECTOR_TESTING')) {
            define('BS_CONNECTOR_TESTING', true);
        }
        $dir = dirname(__DIR__, 2).'/integrations/woocommerce-connector/includes';
        require_once $dir.'/class-bs-vat.php';
        require_once $dir.'/class-bs-settings.php';
    }

    public function test_input_is_normalized_and_the_prefix_wins_over_the_billing_country(): void
    {
        $this->assertNull(BS_Vat::normalize('  '));
        $this->assertSame(['country' => 'DE', 'number' => '811191002', 'vat_id' => 'DE811191002'], BS_Vat::normalize('de 811.191-002', 'AT'));
        $this->assertSame(['country' => 'DE', 'number' => '811191002', 'vat_id' => 'DE811191002'], BS_Vat::normalize('811 191 002', 'de'));
        // Griechenland heißt bei VIES EL -- als Präfix und als Rechnungsland.
        $this->assertSame('EL', BS_Vat::normalize('GR123456789')['country']);
        $this->assertSame('EL123456789', BS_Vat::normalize('123456789', 'GR')['vat_id']);
        // Kein EU-Präfix: Rechnungsland zählt, die Buchstaben gehören zur Nummer.
        $this->assertSame(['country' => 'AT', 'number' => 'U12345678', 'vat_id' => 'ATU12345678'], BS_Vat::normalize('U12345678', 'AT'));
        $this->assertSame(['country' => 'CH', 'number' => 'E123456789', 'vat_id' => 'CHE123456789'], BS_Vat::normalize('CHE-123.456.789', 'CH'));
        $this->assertSame('DECHE123456789', BS_Vat::normalize('CHE123456789', 'DE')['vat_id'], 'fremde Nicht-EU-Kennung bei EU-Rechnungsland: Format falsch');
        $this->assertSame(BS_Vat::INVALID, BS_Vat::precheck('CHE123456789', 'DE')['status']);
    }

    public function test_format_is_checked_per_country(): void
    {
        $valid = [
            'ATU12345678', 'BE0123456789', 'BG123456789', 'BG1234567890', 'CY12345678L', 'CZ12345678', 'CZ1234567890',
            'DE811191002', 'DK12345678', 'EE123456789', 'EL123456789', 'ESX1234567X', 'ESA12345678', 'FI12345678',
            'FRXX123456789', 'FR12345678901', 'HR12345678901', 'HU12345678', 'IE1234567T', 'IE1234567TW', 'IE1A23456T',
            'IT12345678901', 'LT123456789', 'LT123456789012', 'LU12345678', 'LV12345678901', 'MT12345678',
            'NL123456789B01', 'PL1234567890', 'PT123456789', 'RO12', 'RO1234567890', 'SE123456789001', 'SI12345678',
            'SK1234567890', 'XI123456789', 'XIGD123',
        ];
        foreach ($valid as $vat) {
            $pre = BS_Vat::precheck($vat);
            $this->assertNull($pre['status'], "{$vat} sollte an VIES gehen");
        }
        $invalid = ['DE12345678', 'DE1234567890', 'ATU1234567', 'AT12345678', 'NL123456789A01', 'FRIO123456789', 'SE123456789002', 'RO0123', 'BE2123456789', 'DEABCDEFGHI'];
        foreach ($invalid as $vat) {
            $pre = BS_Vat::precheck($vat);
            $this->assertSame(BS_Vat::INVALID, $pre['status'], "{$vat} hat ein falsches Format");
            $this->assertSame('FORMAT', $pre['error']);
        }
        // Ohne Präfix und ohne EU-Rechnungsland: VIES nicht zuständig.
        $this->assertSame(BS_Vat::NOT_APPLICABLE, BS_Vat::precheck('CHE123456789', 'CH')['status']);
        $this->assertSame(BS_Vat::NOT_APPLICABLE, BS_Vat::precheck('GB123456789', 'GB')['status']);
        $this->assertNull(BS_Vat::precheck('', 'DE'));
    }

    public function test_request_body_with_and_without_requester(): void
    {
        $this->assertSame(['countryCode' => 'DE', 'vatNumber' => '811191002'], BS_Vat::request_body('DE', '811191002'));
        $this->assertSame(
            ['countryCode' => 'AT', 'vatNumber' => 'U12345678', 'requesterMemberStateCode' => 'DE', 'requesterNumber' => '811191002'],
            BS_Vat::request_body('AT', 'U12345678', 'DE 811 191 002')
        );
        $this->assertArrayNotHasKey('requesterNumber', BS_Vat::request_body('AT', 'U12345678', 'CH123'));
    }

    public function test_vies_responses_are_mapped(): void
    {
        $valid = BS_Vat::map_response(200, [
            'countryCode' => 'DE', 'vatNumber' => '811191002', 'requestDate' => '2026-11-13T10:00:00.000Z',
            'valid' => true, 'requestIdentifier' => 'WAPIAAAAZ1x', 'name' => '---', 'address' => '---',
        ]);
        $this->assertSame(BS_Vat::VALID, $valid['status']);
        $this->assertFalse($valid['retry']);
        $this->assertSame('WAPIAAAAZ1x', $valid['request_identifier']);
        $this->assertSame('2026-11-13T10:00:00.000Z', $valid['request_date']);
        $this->assertSame('', $valid['name'], '„---“ heißt: Name nicht veröffentlicht');

        $invalid = BS_Vat::map_response(200, ['valid' => false, 'userError' => 'INVALID', 'requestIdentifier' => '']);
        $this->assertSame(BS_Vat::INVALID, $invalid['status']);
        $this->assertFalse($invalid['retry']);
        $this->assertNull($invalid['error']);

        // Dienst des Mitgliedstaats nicht erreichbar -- als userError (200) und als errorWrappers (500).
        foreach ([
            [200, ['valid' => false, 'userError' => 'MS_UNAVAILABLE']],
            [500, ['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE', 'message' => 'x']]]],
            [503, ['actionSucceed' => false, 'errorWrappers' => [['error' => 'SERVICE_UNAVAILABLE']]]],
            [200, ['valid' => false, 'userError' => 'MS_MAX_CONCURRENT_REQ']],
            [500, ['errorWrappers' => [['error' => 'GLOBAL_MAX_CONCURRENT_REQ']]]],
            [500, ['errorWrappers' => [['error' => 'TIMEOUT']]]],
        ] as [$status, $body]) {
            $result = BS_Vat::map_response($status, $body);
            $this->assertSame(BS_Vat::UNCHECKED, $result['status'], json_encode($body));
            $this->assertTrue($result['retry']);
        }
        $this->assertSame('MS_UNAVAILABLE', BS_Vat::map_response(500, ['errorWrappers' => [['error' => 'ms_unavailable']]])['error']);

        // Netzfehler, unlesbare Antwort, Serverfehler ohne Body: nicht geprüft, erneut versuchen.
        $this->assertSame(['status' => BS_Vat::UNCHECKED, 'retry' => true, 'error' => 'NO_RESPONSE'], array_intersect_key(BS_Vat::map_response(null, null), ['status' => 1, 'retry' => 1, 'error' => 1]));
        $this->assertSame('HTTP_502', BS_Vat::map_response(502, null)['error']);
        $this->assertSame(BS_Vat::UNCHECKED, BS_Vat::map_response(200, ['foo' => 'bar'])['status']);

        // Die Nummer selbst ist das Problem: wie ungültig, ohne Wiederholung.
        $input = BS_Vat::map_response(400, ['actionSucceed' => false, 'errorWrappers' => [['error' => 'INVALID_INPUT']]]);
        $this->assertSame(BS_Vat::INVALID, $input['status']);
        $this->assertFalse($input['retry']);
        $this->assertSame('INVALID_INPUT', $input['error']);
    }

    public function test_retry_schedule_is_bounded(): void
    {
        $this->assertSame(15, BS_Vat::retry_delay(1));
        $this->assertSame(1440, BS_Vat::retry_delay(count(BS_Vat::RETRY_MINUTES)));
        $this->assertNull(BS_Vat::retry_delay(count(BS_Vat::RETRY_MINUTES) + 1));
    }

    public function test_secrets_from_wp_config_win_and_db_secrets_can_be_disabled(): void
    {
        $this->assertSame('aus-wp-config', BS_Settings::resolve_secret('aus-wp-config', 'aus-db', true));
        $this->assertSame('aus-wp-config', BS_Settings::resolve_secret('aus-wp-config', 'aus-db', false));
        $this->assertSame('aus-db', BS_Settings::resolve_secret(null, 'aus-db', true), 'Standard: kompatibel zu älteren Installationen');
        $this->assertSame('', BS_Settings::resolve_secret(null, 'aus-db', false), 'bs_connector_allow_db_secrets = false ignoriert die Datenbank');
        $this->assertSame(['secret' => 'BS_CONNECTOR_SECRET', 'inbound_secret' => 'BS_CONNECTOR_INBOUND_SECRET'], BS_Settings::SECRETS);
    }
}
