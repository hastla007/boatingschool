<?php

namespace App\Http\Controllers;

use App\Support\LegalPages;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Öffentliche Seiten Impressum und Datenschutzerklärung der Bootsschule,
 * unter deren Adresse erreichbar (auch ohne Anmeldung und im Wartungsmodus).
 */
class LegalPageController extends Controller
{
    public function show(TenantContext $tenantContext, string $kind): View
    {
        $tenant = $tenantContext->tenant();

        return view('legal.show', [
            'title' => LegalPages::KINDS[$kind],
            'content' => LegalPages::html($kind, $tenant),
        ]);
    }
}
