<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\LearningReminder;
use App\Support\BestEffortMail;
use App\Support\MailPreferences;
use App\Support\TenantContext;

/**
 * Lernerinnerungen an ein Paket inaktiver Lernender (InactiveLearnerController,
 * „Alle erinnern“). Höchstens eine Erinnerung je Person und
 * REMINDER_COOLDOWN_DAYS: tenant_user.reminded_at wird vor dem Versand
 * bedingt gesetzt (zwei gleichzeitige Läufe: nur einer sendet) und bei einem
 * Mailfehler zurückgesetzt -- eine Wiederholung des Jobs schickt deshalb nur
 * die fehlgeschlagenen erneut.
 */
class SendLearningReminders extends MassMailJob
{
    public const REMINDER_COOLDOWN_DAYS = 7;

    /** @param  list<array{user_id: string, days_since: ?int}>  $items */
    public function __construct(public array $items)
    {
        $this->initializeMassMail();
    }

    public function handle(TenantContext $context): void
    {
        $tenant = $context->tenant();
        if (! $tenant) {
            return;
        }
        [, $failed] = self::sendBatch($tenant, $this->items);

        $this->retryIfFailed($failed);
    }

    /**
     * Auch für den direkten Versand im Request (wenige Empfänger, mit Rückmeldung).
     *
     * @param  list<array{user_id: string, days_since: ?int}>  $items
     * @return array{0: int, 1: int} versendet, fehlgeschlagen
     */
    public static function sendBatch(Tenant $tenant, array $items): array
    {
        $members = TenantUser::with('user')->where('tenant_id', $tenant->id)->whereIn('user_id', array_column($items, 'user_id'))
            ->where('status', 'active')->get()->keyBy('user_id');

        $sent = 0;
        $failed = 0;
        foreach ($items as $item) {
            $membership = $members->get($item['user_id']);
            if (! $membership || ! $membership->user || $membership->user->status !== 'active') {
                continue;
            }
            // Inzwischen abbestellt (§ 7 UWG): überspringen.
            if (! MailPreferences::wants($membership->user, MailPreferences::LEARNING_REMINDERS)) {
                continue;
            }
            // Erst bedingt vermerken (zwei Admins gleichzeitig: nur einer
            // sendet), bei Mailfehler zurücksetzen, damit ein neuer Versuch
            // nicht 7 Tage gesperrt ist.
            $claimed = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $membership->user_id)
                ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(self::REMINDER_COOLDOWN_DAYS)))
                ->update(['reminded_at' => now()]);
            if ($claimed === 0) {
                continue;
            }
            if (BestEffortMail::send(fn () => $membership->user->notify(new LearningReminder($tenant, $item['days_since'])), 'Lernerinnerung '.$membership->user_id)) {
                $sent++;
            } else {
                $failed++;
                TenantUser::where('tenant_id', $tenant->id)->where('user_id', $membership->user_id)->update(['reminded_at' => $membership->reminded_at]);
            }
        }

        return [$sent, $failed];
    }
}
