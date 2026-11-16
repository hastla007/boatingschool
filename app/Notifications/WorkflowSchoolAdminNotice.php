<?php

namespace App\Notifications;

use App\Notifications\Concerns\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis an owner/admin einer Bootsschule, ausgelöst von einem Workflow
 * (Befehl school_admin.notice, docs/workflow/README.md). Nur feste Texte:
 * keine Codes, keine Personendaten, kein freier Text aus dem Workflow.
 */
class WorkflowSchoolAdminNotice extends Notification
{
    use EscapesMarkdown, Queueable;

    /** Vorlage => [Betreff, Text] */
    public const TEMPLATES = [
        'codes_delivered' => ['Neue Codes im Codebestand', 'Für Ihre Bootsschule wurden aus einer Shop-Bestellung neue Codes im Codebestand hinterlegt.'],
        'codes_revoked' => ['Codes gesperrt', 'Nach einer Rückerstattung oder einem Storno im Shop wurden unbenutzte Codes Ihrer Bootsschule gesperrt.'],
        'shop_account_linked' => ['Shopkonto verknüpft', 'Ein Kundenkonto des Shops wurde mit Ihrer Bootsschule verknüpft.'],
        'shop_account_deactivated' => ['Shopkonto deaktiviert', 'Ein mit Ihrer Bootsschule verknüpftes Kundenkonto des Shops wurde deaktiviert.'],
        'provisioning_failed' => ['Shopkonto noch nicht angelegt', 'Das Kundenkonto Ihrer Bootsschule im Shop konnte noch nicht angelegt werden. Die Plattform versucht es erneut.'],
    ];

    public function __construct(private readonly string $template, private readonly string $schoolName) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        [$subject, $line] = self::TEMPLATES[$this->template] ?? self::TEMPLATES['codes_delivered'];

        return (new MailMessage)
            ->subject($subject.' – '.$this->schoolName)
            ->line($line)
            ->line('Einzelheiten finden Sie im Admin-Bereich Ihrer Bootsschule.')
            ->line('Bootsschule: '.self::escapeMarkdown($this->schoolName));
    }
}
