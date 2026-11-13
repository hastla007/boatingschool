<?php

namespace App\Notifications;

use App\Models\TenantInvitation;
use App\Notifications\Concerns\AddsNoReplyHint;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Einladung zu einer Bootsschule für eine E-Mail-Adresse, die schon einen
 * Account auf der Plattform hat (siehe ParticipantController::invite,
 * Sicherheitsreview H2) -- die Mitgliedschaft entsteht erst, wenn dieser
 * Link angeklickt wird, während der Klickende mit genau dieser Adresse
 * eingeloggt ist (InvitationAcceptController). Wird an die eingeladene
 * Adresse geschickt, nicht an ein evtl. schon vorhandenes, aber
 * unbestätigtes Fremdkonto mit derselben Adresse.
 */
class TenantInvitationNotification extends Notification
{
    use AddsNoReplyHint;
    use EscapesMarkdown;
    use Queueable;

    public function __construct(private readonly TenantInvitation $invitation) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Subdomain der Bootsschule statt des Request-Hosts (Custom Domain), siehe CanonicalUrl.
        $url = CanonicalUrl::temporarySignedRoute(
            'invitations.accept',
            now()->addDays(7),
            ['invitation' => $this->invitation->id, 'hash' => sha1($this->invitation->email)],
            $this->invitation->tenant,
        );

        $tenantName = $this->invitation->tenant->name ?? 'einer Bootsschule';

        // Team-Einladungen gesiezt (wie der Admin-Bereich), Teilnehmer geduzt.
        $role = $this->invitation->role;
        $formal = $role !== 'learner';
        $as = match ($role) {
            'owner' => 'als Inhaber',
            'admin' => 'als Admin ins Team',
            'instructor' => 'als Ausbilder ins Team',
            'staff' => 'als Mitarbeiter ins Team',
            'support' => 'als Support ins Team',
            default => 'als Teilnehmer',
        };

        $message = (new MailMessage)
            ->subject('Einladung zu '.$tenantName)
            ->line($formal
                // Review-Runde 12: Schulname als Fließtext (kein Markdown/Link).
                ? 'Für diese E-Mail-Adresse existiert bereits ein Konto auf der Plattform. '.self::escapeMarkdown($tenantName)." möchte Sie {$as} aufnehmen."
                : 'Für diese E-Mail-Adresse existiert bereits ein Konto auf der Plattform. '.self::escapeMarkdown($tenantName)." möchte dich {$as} aufnehmen.")
            ->line($formal ? 'Melden Sie sich mit dieser E-Mail-Adresse an und bestätigen Sie die Einladung über den folgenden Link.' : 'Melde dich mit dieser E-Mail-Adresse an und bestätige die Einladung über den folgenden Link.')
            ->action('Einladung bestätigen', $url)
            ->line($formal
                ? 'Haben Sie diese Einladung nicht erwartet, können Sie diese Nachricht ignorieren – ohne Bestätigung ändert sich nichts an Ihrem Konto.'
                : 'Hast du diese Einladung nicht erwartet, kannst du diese Nachricht ignorieren – ohne Bestätigung ändert sich nichts an deinem Konto.');

        return self::withNoReplyHint(MailTemplates::apply($message, 'invitation', ['schule' => $tenantName]), $formal ? 'team' : 'learner');
    }
}
