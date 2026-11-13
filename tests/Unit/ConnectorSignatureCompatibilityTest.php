<?php

namespace Tests\Unit;

use App\Commerce\Signature;
use BS_Delivery_Policy;
use BS_Signature;
use PHPUnit\Framework\TestCase;

/**
 * Plugin und App müssen identisch signieren. Die Plugin-Klassen haben keine
 * WordPress-Abhängigkeit und werden hier direkt geladen.
 */
class ConnectorSignatureCompatibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('BS_CONNECTOR_TESTING')) {
            define('BS_CONNECTOR_TESTING', true);
        }
        $dir = dirname(__DIR__, 2).'/integrations/woocommerce-connector/includes/';
        require_once $dir.'class-bs-signature.php';
        require_once $dir.'class-bs-delivery-policy.php';
    }

    public function test_plugin_and_app_compute_the_same_signature(): void
    {
        $body = json_encode(['order_id' => '1001', 'quantity' => 3, 'company_name' => 'Segelschule Möwe']);
        $this->assertSame(
            Signature::canonical('post', '/api/commerce/v1/orders/lines', '1700000000', 'abc', $body),
            BS_Signature::canonical('post', '/api/commerce/v1/orders/lines', '1700000000', 'abc', $body),
        );
        $this->assertSame(
            Signature::sign('geheim', 'POST', '/api/commerce/v1/orders/lines', '1700000000', 'abc', $body),
            BS_Signature::sign('geheim', 'POST', '/api/commerce/v1/orders/lines', '1700000000', 'abc', $body),
        );
    }

    public function test_plugin_verifies_what_the_app_sends_and_rejects_tampering(): void
    {
        $path = '/wp-json/boatingschool/v1/provision';
        $body = '{"email":"pia@example.test"}';
        $h = Signature::headers('app', 'out-secret', 'POST', $path, $body);
        $args = [$path, $h[Signature::HEADER_TIMESTAMP], $h[Signature::HEADER_NONCE]];

        $this->assertTrue(BS_Signature::verify('out-secret', 'POST', ...[...$args, $body, $h[Signature::HEADER_SIGNATURE]]));
        $this->assertFalse(BS_Signature::verify('out-secret', 'POST', ...[...$args, $body.' ', $h[Signature::HEADER_SIGNATURE]]), 'veränderter Body');
        $this->assertFalse(BS_Signature::verify('anderes', 'POST', ...[...$args, $body, $h[Signature::HEADER_SIGNATURE]]), 'falsches Geheimnis');
        $this->assertFalse(BS_Signature::verify('out-secret', 'POST', '/wp-json/x', $args[1], $args[2], $body, $h[Signature::HEADER_SIGNATURE]), 'anderer Pfad');
        $this->assertFalse(BS_Signature::verify('out-secret', 'POST', ...[...$args, $body, $h[Signature::HEADER_SIGNATURE], (int) $args[1] + 301]), 'abgelaufen');
        $this->assertFalse(BS_Signature::verify('', 'POST', ...[...$args, $body, $h[Signature::HEADER_SIGNATURE]]), 'ohne Geheimnis nie gültig');
    }

    public function test_plugin_headers_are_accepted_by_the_app_algorithm(): void
    {
        $h = BS_Signature::headers('shop', 'test-secret', 'GET', '/api/commerce/v1/catalog', '');
        $this->assertSame(
            Signature::sign('test-secret', 'GET', '/api/commerce/v1/catalog', $h['X-BS-Timestamp'], $h['X-BS-Nonce'], ''),
            $h['X-BS-Signature'],
        );
    }

    public function test_delivery_policy(): void
    {
        $this->assertSame([BS_Delivery_Policy::DELIVERED, null], BS_Delivery_Policy::decide(201, 1));
        $this->assertSame([BS_Delivery_Policy::DELIVERED, null], BS_Delivery_Policy::decide(200, 3));
        // Keine Antwort / Serverfehler / 429: später erneut, mit wachsendem Abstand.
        $this->assertSame([BS_Delivery_Policy::RETRY, 1], BS_Delivery_Policy::decide(null, 1));
        $this->assertSame([BS_Delivery_Policy::RETRY, 5], BS_Delivery_Policy::decide(503, 2));
        $this->assertSame([BS_Delivery_Policy::RETRY, 1], BS_Delivery_Policy::decide(429, 1));
        $this->assertSame([BS_Delivery_Policy::RETRY, 30], BS_Delivery_Policy::decide(null, 3));
        $this->assertSame([BS_Delivery_Policy::FAILED, null], BS_Delivery_Policy::decide(null, 4));
        // Fachliche Ablehnung: nicht wiederholen.
        $this->assertSame([BS_Delivery_Policy::FAILED, null], BS_Delivery_Policy::decide(403, 1));
        $this->assertSame([BS_Delivery_Policy::FAILED, null], BS_Delivery_Policy::decide(409, 1));
        // Tageskontingent der Schule: nicht automatisch wiederholen (Freigabe nötig).
        $this->assertSame([BS_Delivery_Policy::FAILED, null], BS_Delivery_Policy::decide(429, 1, 'CODE_QUOTA_EXCEEDED'));
        $this->assertSame([BS_Delivery_Policy::RETRY, 1], BS_Delivery_Policy::decide(429, 1, 'RATE_LIMITED'));
        $this->assertSame('woocommerce:1001:7', BS_Delivery_Policy::reference('woocommerce', '1001', '7'));
    }
}
