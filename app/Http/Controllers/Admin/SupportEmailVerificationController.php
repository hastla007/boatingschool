<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\VerifySupportEmail;
use App\Models\Tenant;
use App\Support\BestEffortMail;
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

        $mailSent = static::sendVerificationMail($tenant);

        return back()->with('status', $mailSent
            ? 'support-email-verification-link-sent'
            : 'Die Bestätigungs-Mail konnte nicht versendet werden -- bitte später erneut versuchen.');
    }

    public function verify(Request $request, TenantContext $tenantContext, Tenant $tenant): Response
    {
        // Sicherheitsreview N7: {tenant} aus der Route muss zum serverseitig
        // aufgelösten Mandanten passen. Die Signatur macht das schon praktisch
        // harmlos, aber eine Abweichung führte bislang zu einem rohen Fehler,
        // weil tenant_branding einer fremden Tenant-ID per RLS ohnehin nie
        // sichtbar ist -- jetzt ein sauberer 404 statt eines Absturzes.
        abort_unless($tenant->id === $tenantContext->id(), 404);

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

    /**
     * Bug-Review F9: Mail::to(...)->send(...) lief hier bisher synchron und
     * ungefangen -- ein SMTP-Fehler ergab HTTP 500, obwohl beim Aufruf aus
     * BrandingController::update() das eigentliche Branding-Update (inkl.
     * der neuen Support-E-Mail) bereits erfolgreich gespeichert war. Siehe
     * BestEffortMail (Bug-Review B31) für denselben Mechanismus an anderer
     * Stelle dieser App.
     */
    public static function sendVerificationMail(Tenant $tenant): bool
    {
        $branding = $tenant->branding;

        $verificationUrl = URL::temporarySignedRoute(
            'admin.branding.support-email.verify',
            now()->addMinutes(60),
            ['tenant' => $tenant->id, 'hash' => sha1($branding->support_email)]
        );

        return BestEffortMail::send(
            fn () => Mail::to($branding->support_email)->send(new VerifySupportEmail($tenant->name, $verificationUrl)),
            "Support-E-Mail-Bestätigung Bootsschule {$tenant->id}"
        );
    }
}
