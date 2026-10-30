<?php

namespace Tests\Feature;

use App\Models\RadioTrainingProgress;
use App\Models\WillkommensboxOrder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Datenexport unvollständig): radio_training_progress (freie
 * Mitschriften im Funk-Training), Land/Anmerkung/Versanddaten der
 * Willkommensbox-Bestellung und eine noch unbestätigte neue E-Mail-Adresse
 * (pending_email) fehlten im Auskunfts-Export.
 */
class PersonalDataExportIncludesRadioTrainingAndOrderDetailsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_export_includes_radio_training_progress_order_details_and_pending_email(): void
    {
        $tenant = $this->createTestTenant();
        $otherTenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($tenant, $otherTenant, $learner) {
            $learner->forceFill(['pending_email' => 'neu-'.$learner->email])->save();

            RadioTrainingProgress::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'item_type' => RadioTrainingProgress::TYPE_TEXT,
                'item_key' => 'SRC-07',
                'completed' => true,
                'streak' => 0,
                'answers' => ['transcript' => 'Mayday, hier ist Segelyacht Testwind'],
            ]);
            // Andere Bootsschule: darf im Export dieser Schule nicht auftauchen.
            RadioTrainingProgress::create([
                'tenant_id' => $otherTenant->id,
                'user_id' => $learner->id,
                'item_type' => RadioTrainingProgress::TYPE_VOCAB,
                'item_key' => 'VOC-SRC-001',
            ]);

            WillkommensboxOrder::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'street' => 'Teststraße 1',
                'postal_code' => '12345',
                'city' => 'Teststadt',
                'country' => 'Österreich',
                'note' => 'Bitte beim Nachbarn abgeben',
                'shipped_at' => now(),
                'tracking_number' => 'DHL-123456',
            ]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data');
        $response->assertOk();
        $data = json_decode($response->getContent(), true);

        $this->assertSame('neu-'.$learner->email, $data['profile']['pending_email']);

        $this->assertCount(1, $data['radio_training_progress']);
        $this->assertSame('SRC-07', $data['radio_training_progress'][0]['item_key']);
        $this->assertSame(['transcript' => 'Mayday, hier ist Segelyacht Testwind'], $data['radio_training_progress'][0]['answers']);
        $this->assertTrue($data['radio_training_progress'][0]['completed']);

        $this->assertCount(1, $data['willkommensbox_orders']);
        $order = $data['willkommensbox_orders'][0];
        $this->assertSame('Österreich', $order['country']);
        $this->assertSame('Bitte beim Nachbarn abgeben', $order['note']);
        $this->assertSame('DHL-123456', $order['tracking_number']);
        $this->assertNotNull($order['shipped_at']);
    }
}
