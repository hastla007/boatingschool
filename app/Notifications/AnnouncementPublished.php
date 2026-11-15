<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\SentFromSchool;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mitteilung der Bootsschule per Mail (Text der Schule als reiner Text, kein
 * Markdown/Links). Audit ARC-2: über die Queue (bis zu 500 Empfänger); Titel
 * und Text werden mitgegeben, die Schule nur als ID.
 */
class AnnouncementPublished extends Notification implements ShouldQueue
{
    use EscapesMarkdown, Queueable, SentFromSchool;

    private readonly string $title;

    private readonly string $body;

    public function __construct(Tenant $tenant, TenantAnnouncement $announcement)
    {
        $this->captureSchool($tenant->id);
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return $this->school() !== null;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tenant = $this->school();
        $message = (new MailMessage)
            ->subject($this->title)
            ->line('Mitteilung von '.self::escapeMarkdown($tenant->name).':');
        foreach (preg_split('/\R{2,}/u', trim($this->body)) as $paragraph) {
            $message->line(self::escapeMarkdown($paragraph));
        }
        $message->action('Zur Lernplattform', CanonicalUrl::root($tenant).route('dashboard', [], false));

        return MailTemplates::apply($message, 'announcement', ['schule' => $tenant->name, 'titel' => $this->title]);
    }
}
