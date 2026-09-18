<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\VerifySupportEmail;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifizierung der Bootsschul-Support-E-Mail, analog zur Nutzer-
 * E-Mail-Verifizierung (VerifyEmailController), aber unabhängig vom
 * Auth-Guard, da tenant_branding kein Notifiable/MustVerifyEmail-Modell ist.
 */
class SupportEmailVerificationController extends Controller
{
    public function send(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $branding = $tenant->branding;

        abort_if(! $branding->support_email, 422, 'Für diese Bootsschule ist keine Support-E-Mail hinterlegt.');

        if ($branding->hasVerifiedSupportEmail()) {
            return back();
        }

        static::sendVerificationMail($tenant);

        return back()->with('status', 'support-email-verification-link-sent');
    }

    public function verify(Request $request, Tenant $tenant): Response
    {
        $branding = $tenant->branding;

        abort_unless(
            $branding->support_email && hash_equals(sha1($branding->support_email), (string) $request->route('hash')),
            403
        );

        if (! $branding->support_email_verified_at) {
            $branding->forceFill(['support_email_verified_at' => now()])->save();
        }

        return redirect()->route('admin.dashboard', ['tab' => 'branding'])->with('status', 'Support-E-Mail bestätigt.');
    }

    public static function sendVerificationMail(Tenant $tenant): void
    {
        $branding = $tenant->branding;

        $verificationUrl = URL::temporarySignedRoute(
            'admin.branding.support-email.verify',
            now()->addMinutes(60),
            ['tenant' => $tenant->id, 'hash' => sha1($branding->support_email)]
        );

        Mail::to($branding->support_email)->send(new VerifySupportEmail($tenant->name, $verificationUrl));
    }
}
