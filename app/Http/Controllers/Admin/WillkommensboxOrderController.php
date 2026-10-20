<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WillkommensboxOrder;
use App\Support\TenantContext;
use Illuminate\Http\Request;
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

        $willkommensboxOrder->update([
            'shipped_at' => now(),
            'tracking_number' => trim((string) ($validated['tracking_number'] ?? '')) ?: null,
        ]);

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'willkommensbox-order.ship',
            'willkommensbox_order', $willkommensboxOrder->id, null, $willkommensboxOrder->fresh()->toArray()
        );

        return back()->with('status', 'Willkommensbox als versendet markiert.');
    }
}
