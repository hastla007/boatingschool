<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WillkommensboxOrder;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versandlabel-PDF (Absender/Empfänger, siehe resources/views/admin/
 * willkommensbox/label.blade.php) für eine Willkommensbox-Bestellung, in
 * einer der von der Bootsschule im Branding-Tab freigegebenen Papiergrößen
 * (siehe TenantBranding::enabledLabelSizes). Zu finden auf der Teilnehmer-
 * Detailseite (Admin\ParticipantController::show).
 */
class WillkommensboxLabelController extends Controller
{
    private const SIZES = ['a4', 'a5', 'a6'];

    public function show(Request $request, TenantContext $tenantContext, WillkommensboxOrder $willkommensboxOrder, string $size): Response
    {
        abort_unless($willkommensboxOrder->tenant_id === $tenantContext->id(), 404);
        abort_unless(in_array($size, self::SIZES, true), 404);

        $tenant = $tenantContext->tenant();
        $branding = $tenant->branding;

        abort_unless(in_array($size, $branding->enabledLabelSizes(), true), 403, 'Diese Labelgröße ist für Ihre Bootsschule nicht aktiviert.');

        $willkommensboxOrder->loadMissing('user');

        $pdf = Pdf::loadView('admin.willkommensbox.label', [
            'order' => $willkommensboxOrder,
            'branding' => $branding,
            'tenant' => $tenant,
            'size' => $size,
        ])->setPaper(strtoupper($size), 'landscape');

        return $pdf->download("versandlabel-{$size}-{$willkommensboxOrder->id}.pdf");
    }
}
