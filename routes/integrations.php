<?php

use App\Http\Controllers\Integrations\FormCaptainLeadController;
use App\Http\Controllers\Superadmin\LeadController;
use App\Http\Middleware\AuditSuperadminChanges;
use App\Http\Middleware\Integrations\VerifyFormCaptainSignature;
use Illuminate\Support\Facades\Route;

/*
| Lead-Eingang für Form Captain (docs/integrations/form-captain-leads.md),
| eingebunden in bootstrap/app.php (withRouting then).
|
| 1. Signierter Endpunkt /api/integrations/v1/form-captain: bewusst ohne die
|    api-Gruppe der Mobile-API und getrennt von /api/commerce/v1 -- eigene
|    Middleware, eigener Schlüssel (config/form_captain.php), eigene
|    Drosselung, keine Commerce-Rechte. Die Signaturprüfung steht vor
|    admin-db (einzige priorisierte Middleware hier, keine Umsortierung).
| 2. Lead-Inbox im Superadmin (/superadmin/leads), mit derselben Middleware
|    wie routes/superadmin.php.
*/

Route::middleware([VerifyFormCaptainSignature::class, 'admin-db'])
    ->prefix('api/integrations/v1/form-captain')->name('integrations.form-captain.')
    ->group(function () {
        Route::post('/leads', [FormCaptainLeadController::class, 'store'])->name('leads.store');
        Route::delete('/leads/{leadId}', [FormCaptainLeadController::class, 'destroy'])
            ->where('leadId', 'L-[0-9A-Za-z]{26}')->name('leads.destroy');
        Route::post('/ping', [FormCaptainLeadController::class, 'ping'])->name('ping');
    });

Route::middleware(['web', 'auth', 'superadmin', 'admin-db', 'verified', AuditSuperadminChanges::class])
    ->prefix('superadmin/leads')->name('superadmin.leads.')
    ->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::get('/{lead}', [LeadController::class, 'show'])->whereUuid('lead')->name('show');
        Route::patch('/{lead}', [LeadController::class, 'update'])->whereUuid('lead')->name('update');
        Route::post('/{lead}/reject', [LeadController::class, 'reject'])->whereUuid('lead')->name('reject');
        Route::delete('/{lead}', [LeadController::class, 'destroy'])->whereUuid('lead')->name('destroy');
    });
