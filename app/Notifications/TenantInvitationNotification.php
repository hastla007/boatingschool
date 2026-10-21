<?php

namespace App\Notifications;

use App\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

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
    use Queueable;

    public function __construct(private readonly TenantInvitation $invitation)
    {
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'invitations.accept',
            now()->addDays(7),
            ['invitation' => $this->invitation->id, 'hash' => sha1($this->invitation->email)]
        );

        $tenantName = $this->invitation->tenant->name ?? 'einer Bootsschule';

        return (new MailMessage)
            ->subject('Einladung zu '.$tenantName)
            ->line("Für diese E-Mail-Adresse existiert bereits ein Konto auf der Plattform. {$tenantName} möchte dich als Teilnehmer hinzufügen.")
            ->line('Melde dich mit dieser E-Mail-Adresse an und bestätige die Einladung über den folgenden Link.')
            ->action('Einladung bestätigen', $url)
            ->line('Hast du diese Einladung nicht erwartet, kannst du diese Nachricht ignorieren -- ohne Bestätigung ändert sich nichts an deinem Konto.');
    }
}
