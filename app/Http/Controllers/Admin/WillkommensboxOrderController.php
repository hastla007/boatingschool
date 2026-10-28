<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WillkommensboxOrder;
use App\Notifications\WillkommensboxOrderShipped;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versandbestätigung einer Willkommensbox-Bestellung (siehe
 * WillkommensboxOrder) -- die Bootsschule markiert die Bestellung als
 * versendet, sobald sie das Paket losgeschickt hat, optional mit
 * Trackingnummer. Zu finden auf der Teilnehmer-Detailseite
 * (Admin\ParticipantController::show).
 */
class WillkommensboxOrderController extends Controller
{
    public function ship(Request $request, TenantContext $tenantContext, WillkommensboxOrder $willkommensboxOrder): Response
    {
        abort_unless($willkommensboxOrder->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        // Bug-Review (Willkommensbox -- Doppelklick auf "Versendet" schickt
        // zwei Mails): update() lief hier bisher unbedingt -- ein
        // Doppelklick (oder zwei nahezu gleichzeitige Requests) markierte
        // die Bestellung zweimal als versendet und verschickte dabei auch
        // zweimal die Versandbestätigung an den Lerner. Die WHERE-Bedingung
        // macht das UPDATE atomar und wirkungslos, sobald die Bestellung
        // bereits versendet wurde.
        $updated = WillkommensboxOrder::where('id', $willkommensboxOrder->id)
            ->whereNull('shipped_at')
            ->update([
                'shipped_at' => now(),
                'tracking_number' => trim((string) ($validated['tracking_number'] ?? '')) ?: null,
            ]);

        if ($updated === 0) {
            return back()->with('status', 'Willkommensbox wurde bereits als versendet markiert.');
        }

        $willkommensboxOrder->refresh();

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'willkommensbox-order.ship',
            'willkommensbox_order', $willkommensboxOrder->id, null, $willkommensboxOrder->toArray()
        );

        // Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen
        // und Willkommensbox wurde bisher keine Mail verschickt -- der
        // Lerner bemerkte den Versand nur, wenn er von selbst wieder auf
        // die Willkommensbox-Seite sah.
        BestEffortMail::send(
            fn () => Notification::send($willkommensboxOrder->user, new WillkommensboxOrderShipped($willkommensboxOrder)),
            "Versandbestätigung Willkommensbox {$willkommensboxOrder->id}"
        );

        return back()->with('status', 'Willkommensbox als versendet markiert.');
    }
}
