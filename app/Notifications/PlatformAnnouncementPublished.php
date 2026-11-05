<?php

namespace App\Notifications;

use App\Models\PlatformAnnouncement;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Support\CanonicalUrl;
use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mitteilung des Plattformbetreibers per Mail an Inhaber/Admins einer Bootsschule (reiner Text). */
class PlatformAnnouncementPublished extends Notification
{
    use EscapesMarkdown, Queueable;

    public function __construct(private readonly PlatformAnnouncement $announcement, private readonly Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $platform = PlatformSetting::siteName();
        $message = (new MailMessage)
            ->subject($this->announcement->title)
            ->line('Mitteilung von '.self::escapeMarkdown($platform).' an '.self::escapeMarkdown($this->tenant->name).':');
        foreach (preg_split('/\R{2,}/u', trim($this->announcement->body)) as $paragraph) {
            $message->line(self::escapeMarkdown($paragraph));
        }
        $message->action('Zum Admin-Bereich', CanonicalUrl::root($this->tenant).route('admin.dashboard', [], false));

        return MailTemplates::apply($message, 'platform_announcement', ['plattform' => $platform, 'schule' => $this->tenant->name, 'titel' => $this->announcement->title]);
    }
}
