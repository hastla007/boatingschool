<?php

namespace App\Console\Commands;

use App\Models\Entitlement;
use App\Notifications\EntitlementExpiringSoon;
use App\Support\BestEffortMail;
use Illuminate\Console\Command;

/**
 * Erinnert Lernende per E-Mail, wenn ein Kurszugang innerhalb von
 * Entitlement::EXPIRY_WARNING_DAYS Tagen abläuft -- einmal je Ablaufdatum.
 * Läuft mandantenübergreifend über die Owner-Verbindung (wie
 * ExpireEntitlements).
 */
class RemindExpiringEntitlements extends Command
{
    protected $signature = 'app:remind-expiring-entitlements';

    protected $description = 'Schickt eine Erinnerung an Lernende, deren Kurszugang bald abläuft.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $sent = 0;
        Entitlement::query()
            ->with(['user', 'course', 'tenant.branding'])
            ->where('status', 'active')
            ->whereNull('expiry_reminded_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '>', now())
            ->where('valid_until', '<=', now()->addDays(Entitlement::EXPIRY_WARNING_DAYS))
            ->chunkById(200, function ($entitlements) use (&$sent) {
                foreach ($entitlements as $entitlement) {
                    if (! $entitlement->user || ! $entitlement->course || ! $entitlement->tenant) {
                        continue;
                    }
                    // Erst vermerken, dann senden: ein Mailfehler führt nicht
                    // zu täglich neuen Versuchen und doppelten Mails.
                    Entitlement::whereKey($entitlement->id)->update(['expiry_reminded_at' => now()]);
                    if (BestEffortMail::send(fn () => $entitlement->user->notify(new EntitlementExpiringSoon($entitlement)), 'Ablauf-Erinnerung '.$entitlement->id)) {
                        $sent++;
                    }
                }
            });

        $this->line("{$sent} Erinnerung(en) verschickt.");

        return self::SUCCESS;
    }
}
