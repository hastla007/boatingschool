<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Bestätigungs-Mail für die Support-E-Mail einer Bootsschule (Admin >
 * Branding-Einstellungen) -- analog zur Nutzer-E-Mail-Verifizierung, aber
 * unabhängig vom Auth-Guard, da tenant_branding kein Notifiable ist.
 */
class VerifySupportEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $tenantName, public string $verificationUrl) {}

    public function build(): self
    {
        // Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste): die Mail
        // geht an die Support-Adresse der Bootsschule, die der Admin selbst
        // im Branding-Tab (siezt durchgehend) hinterlegt hat -- der Betreff
        // duzte trotzdem.
        return $this->subject('Bitte bestätigen Sie die Support-E-Mail für '.$this->tenantName)
            ->view('emails.verify-support-email');
    }
}
