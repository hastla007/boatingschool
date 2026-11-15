<?php

namespace App\Notifications;

use App\Models\PlatformAnnouncement;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\SentFromSchool;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mitteilung des Plattformbetreibers per Mail an Inhaber/Admins einer
 * Bootsschule (reiner Text). Audit ARC-2: über die Queue (bis zu 1000
 * Empfänger); bewusst ohne Schulkontext (Absender ist die Plattform), die
 * angeschriebene Schule nur für Anrede und Link.
 */
class PlatformAnnouncementPublished extends Notification implements ShouldQueue
{
    use EscapesMarkdown, Queueable, SentFromSchool;

    private readonly string $title;

    private readonly string $body;

    private readonly string $tenantId;

    public function __construct(PlatformAnnouncement $announcement, Tenant $tenant)
    {
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->tenantId = $tenant->id;
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return Tenant::whereKey($this->tenantId)->exists();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        $platform = PlatformSetting::siteName();
        $message = (new MailMessage)
            ->subject($this->title)
            ->line('Mitteilung von '.self::escapeMarkdown($platform).' an '.self::escapeMarkdown($tenant->name).':');
        foreach (preg_split('/\R{2,}/u', trim($this->body)) as $paragraph) {
            $message->line(self::escapeMarkdown($paragraph));
        }
        $message->action('Zum Admin-Bereich', CanonicalUrl::root($tenant).route('admin.dashboard', [], false));

        return MailTemplates::apply($message, 'platform_announcement', ['plattform' => $platform, 'schule' => $tenant->name, 'titel' => $this->title]);
    }
}
