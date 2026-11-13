<?php

namespace App\Jobs;

use App\Models\PlatformAnnouncement;
use App\Models\TenantUser;
use App\Notifications\PlatformAnnouncementPublished;
use App\Support\OwnerConnection;

/**
 * Plattform-Mitteilung per Mail an ein Paket von Inhabern/Admins
 * (Superadmin, PlatformAnnouncementController). Bewusst ohne Mandant
 * (tenantId null, Nil-UUID): die Empfänger gehören zu vielen Schulen, die
 * Abfragen laufen deshalb wie der Superadmin-Bereich selbst über die
 * Owner-Verbindung.
 */
class SendPlatformAnnouncementMails extends MassMailJob
{
    /** @param  list<array{user_id: string, tenant_id: string}>  $recipients */
    public function __construct(public string $announcementId, public array $recipients)
    {
        $this->initializeMassMail(null);
        $this->tenantId = null;
    }

    public function handle(): void
    {
        $failed = OwnerConnection::run(function () {
            $announcement = PlatformAnnouncement::find($this->announcementId);
            if (! $announcement) {
                return 0;
            }

            $sent = 0;
            $failed = 0;
            foreach ($this->recipients as $recipient) {
                $membership = TenantUser::with(['user', 'tenant'])->where('tenant_id', $recipient['tenant_id'])->where('user_id', $recipient['user_id'])
                    ->where('status', 'active')->first();
                if (! $membership || ! $membership->user || $membership->user->status !== 'active' || ! $membership->tenant) {
                    continue;
                }
                $result = $this->sendOnce($recipient['user_id'], fn () => $membership->user->notify(new PlatformAnnouncementPublished($announcement, $membership->tenant)), 'Plattform-Mitteilung '.$announcement->id);
                $sent += $result === true ? 1 : 0;
                $failed += $result === false ? 1 : 0;
            }
            if ($sent > 0) {
                PlatformAnnouncement::whereKey($announcement->id)->increment('emailed_count', $sent);
            }

            return $failed;
        });

        $this->retryIfFailed($failed);
    }
}
