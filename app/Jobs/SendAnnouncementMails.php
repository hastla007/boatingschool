<?php

namespace App\Jobs;

use App\Models\TenantAnnouncement;
use App\Models\TenantUser;
use App\Notifications\AnnouncementPublished;
use App\Support\TenantContext;

/**
 * Mitteilung einer Bootsschule per Mail an ein Paket von Lernenden
 * (AnnouncementController). Läuft im Mandantenkontext der Schule
 * (TenantAware), zählt zugestellte Mails in emailed_count.
 */
class SendAnnouncementMails extends MassMailJob
{
    /** @param  list<string>  $userIds */
    public function __construct(public string $announcementId, public array $userIds)
    {
        $this->initializeMassMail();
    }

    public function handle(TenantContext $context): void
    {
        $tenant = $context->tenant();
        $announcement = TenantAnnouncement::find($this->announcementId);
        if (! $tenant || ! $announcement) {
            return;
        }

        // Empfänger erst jetzt laden: inzwischen gesperrte Konten bekommen nichts.
        $members = TenantUser::with('user')->where('tenant_id', $tenant->id)->whereIn('user_id', $this->userIds)
            ->where('status', 'active')->get()->filter(fn ($m) => $m->user && $m->user->status === 'active');

        $sent = 0;
        $failed = 0;
        foreach ($members as $membership) {
            $result = $this->sendOnce($membership->user_id, fn () => $membership->user->notify(new AnnouncementPublished($tenant, $announcement)), 'Mitteilung '.$announcement->id);
            $sent += $result === true ? 1 : 0;
            $failed += $result === false ? 1 : 0;
        }
        if ($sent > 0) {
            TenantAnnouncement::whereKey($announcement->id)->increment('emailed_count', $sent);
        }

        $this->retryIfFailed($failed);
    }
}
