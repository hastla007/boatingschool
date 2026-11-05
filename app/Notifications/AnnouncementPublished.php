<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mitteilung der Bootsschule per Mail (Text der Schule als reiner Text, kein Markdown/Links). */
class AnnouncementPublished extends Notification
{
    use EscapesMarkdown, Queueable;

    public function __construct(private readonly Tenant $tenant, private readonly TenantAnnouncement $announcement) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->announcement->title)
            ->line('Mitteilung von '.self::escapeMarkdown($this->tenant->name).':');
        foreach (preg_split('/\R{2,}/u', trim($this->announcement->body)) as $paragraph) {
            $message->line(self::escapeMarkdown($paragraph));
        }
        $message->action('Zur Lernplattform', CanonicalUrl::root($this->tenant).route('dashboard', [], false));

        return MailTemplates::apply($message, 'announcement', ['schule' => $this->tenant->name, 'titel' => $this->announcement->title]);
    }
}
