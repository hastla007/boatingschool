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
        $failed = 0;
        Entitlement::query()
            ->with(['user', 'course', 'tenant.branding'])
            ->where('status', 'active')
            ->whereNull('expiry_reminded_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '>', now())
            ->where('valid_until', '<=', now()->addDays(Entitlement::EXPIRY_WARNING_DAYS))
            // Nur laufende Schulen und aktive Mitgliedschaften: wer gesperrt
            // ist oder dessen Schule geschlossen wird, bekommt keine
            // Aufforderung, einen Verlängerungscode zu kaufen.
            ->whereHas('tenant', fn ($q) => $q->whereIn('status', ['active', 'trial']))
            ->whereExists(fn ($q) => $q->from('tenant_user')->whereColumn('tenant_user.tenant_id', 'entitlement.tenant_id')
                ->whereColumn('tenant_user.user_id', 'entitlement.user_id')->where('tenant_user.status', 'active'))
            ->chunkById(200, function ($entitlements) use (&$sent, &$failed) {
                foreach ($entitlements as $entitlement) {
                    if (! $entitlement->user || ! $entitlement->course || ! $entitlement->tenant) {
                        continue;
                    }
                    // Erst vermerken, dann senden: ein Mailfehler führt nicht
                    // zu täglich neuen Versuchen und doppelten Mails.
                    Entitlement::whereKey($entitlement->id)->update(['expiry_reminded_at' => now()]);
                    if (BestEffortMail::send(fn () => $entitlement->user->notify(new EntitlementExpiringSoon($entitlement)), 'Ablauf-Erinnerung '.$entitlement->id)) {
                        $sent++;
                    } else {
                        // Mail nicht raus (z. B. SMTP gestört): beim nächsten
                        // Lauf erneut versuchen statt die Erinnerung zu verlieren.
                        Entitlement::whereKey($entitlement->id)->update(['expiry_reminded_at' => null]);
                        $failed++;
                    }
                }
            });

        $this->line("{$sent} Erinnerung(en) verschickt.");
        if ($failed > 0) {
            $this->error("{$failed} Erinnerung(en) konnten nicht verschickt werden und werden beim nächsten Lauf erneut versucht.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
