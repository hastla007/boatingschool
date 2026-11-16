<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WooCommerce-Plugin: Funde aus dem lokalen E2E-Test gegen echtes
 * WordPress/WooCommerce (docs/commerce/staging-test.md). Die Plugin-Klassen
 * laufen mit einem minimalen WordPress-/WooCommerce-Ersatz in einem eigenen
 * Prozess (connector-wc-harness.php) -- kein Test gegen echtes WooCommerce.
 */
class ConnectorOrdersAndCheckoutTest extends TestCase
{
    private function harness(string $scenario, array $options = []): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/connector-wc-harness.php', $scenario, json_encode($options)]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_courses_the_school_cannot_receive_are_refused_when_adding_to_the_cart(): void
    {
        // Schule ohne SBF-SEE: vorher landete der Artikel im Warenkorb und fiel
        // erst an der Kasse auf.
        $out = $this->harness('add_to_cart', ['status' => [
            'status' => 'linked', 'can_purchase' => true, 'tenant' => ['name' => 'Schule B'], 'deliverable_course_codes' => ['SBF-BIN-MOTOR'],
        ]]);

        $this->assertFalse($out[1]['passed']);
        $this->assertStringContainsString('„Kurscode SBF See“ kann für Ihre Bootsschule derzeit nicht geliefert werden', $out[1]['notice']);
        $this->assertFalse($out[2]['passed'], 'Verlängerungscode ebenso');
        $this->assertTrue($out[3]['passed'], 'Produktcodes hängen nicht an einem Kurs');
        $this->assertTrue($out[4]['passed'], 'normale Produkte unberührt');
    }

    public function test_add_to_cart_without_deliverable_course_list_is_not_restricted(): void
    {
        // Ältere App ohne deliverable_course_codes: nicht prüfen.
        $out = $this->harness('add_to_cart', ['status' => [
            'status' => 'linked', 'can_purchase' => true, 'tenant' => ['name' => 'Schule A'], 'deliverable_course_codes' => null,
        ]]);

        $this->assertTrue($out[1]['passed']);
        $this->assertTrue($out[2]['passed']);
    }

    public function test_rejected_accounts_are_sent_to_support_instead_of_the_link_page(): void
    {
        $out = $this->harness('add_to_cart', ['status' => [
            'status' => 'rejected', 'can_purchase' => false, 'tenant' => null, 'deliverable_course_codes' => null,
        ]]);

        $this->assertFalse($out[1]['passed']);
        $this->assertStringContainsString('Bitte wenden Sie sich an den Support', $out[1]['notice']);
        $this->assertStringNotContainsString('verknüpfen Sie', $out[1]['notice']);
        $this->assertTrue($out[4]['passed']);
    }

    public function test_reactivating_a_cancelled_order_after_delivery_warns_once_and_does_not_redeliver(): void
    {
        // Vorher: gelieferte Positionen blieben „delivered“ -- die Reaktivierung
        // lief still durch, ohne Notiz und Alarm-Mail.
        $out = $this->harness('cancel_after_delivery');

        $this->assertSame([['bs_connector_void_item', [50, 11, null, 'cancel-50-11']]], $out['voids']);
        $this->assertSame(0, $out['deliveries'], 'keine Nachlieferung');
        $this->assertCount(1, $out['notes']);
        $this->assertStringContainsString('Die Position war storniert', $out['notes'][0]);
        $this->assertSame(['[Shop] Stornierte Bestellung 50 wieder aktiv'], $out['mails']);
        $this->assertSame('delivered', $out['item_status']);
        $this->assertSame(1, $out['notes_after_second'], 'nur einmal melden');
        $this->assertSame(1, $out['mails_after_second']);
    }
}
