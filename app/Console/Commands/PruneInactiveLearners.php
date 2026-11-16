<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InactiveAccountDeletionWarning;
use App\Services\AccountErasureService;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Löschfrist für inaktive Lernende (Speicherbegrenzung, Art. 5 Abs. 1 lit. e
 * DSGVO): Konten ohne Anmeldung und ohne Lernaktivität seit
 * data_retention.inactive_learners.months Monaten und ohne aktiven
 * Kurszugang bekommen eine Vorwarnung per Mail (inactivity_warned_at) und
 * werden frühestens warning_days Tage danach über AccountErasureService
 * gelöscht -- in jeder Bootsschule, wie eine Löschung durch den Superadmin.
 *
 * Nie betroffen: Superadmins, Team-Mitglieder einer Schule (Owner, Admin,
 * Ausbilder) und nie aktivierte Einladungs-Konten (die räumt
 * app:prune-personal-data mit eigener Frist ab). Ohne gesetzte Monatszahl
 * (DATA_RETENTION_INACTIVE_LEARNER_MONTHS) tut der Befehl nichts.
 *
 * Neue Anmeldung oder Lernaktivität nach der Vorwarnung, ein neuer
 * Kurszugang oder eine Team-Rolle heben die Vorwarnung wieder auf.
 * Läuft mandantenübergreifend über die Owner-Verbindung.
 */
class PruneInactiveLearners extends Command
{
    protected $signature = 'app:prune-inactive-learners
        {--dry-run : Nur anzeigen, wie viele Konten gewarnt bzw. gelöscht würden}';

    protected $description = 'Warnt inaktive Lernende ohne aktiven Kurszugang vor der Löschung und löscht sie nach Ablauf der Frist.';

    public function handle(AccountErasureService $erasure): int
    {
        $months = config('data_retention.inactive_learners.months');
        if ($months === null || (int) $months < 1) {
            $this->line('Inaktive Lernende: Löschung deaktiviert (DATA_RETENTION_INACTIVE_LEARNER_MONTHS).');

            return self::SUCCESS;
        }
        $months = (int) $months;
        $warningDays = max(1, (int) config('data_retention.inactive_learners.warning_days', 30));

        config(['database.default' => 'pgsql_admin']);
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $deleteCutoff = $now->copy()->subMonths($months);
        // Die Vorwarnung kommt warning_days Tage vor dem Ablauf der Frist.
        $warnCutoff = $deleteCutoff->copy()->addDays($warningDays);

        // 1. Vorwarnungen aufheben, die nicht mehr zutreffen.
        $reset = User::query()->whereNotNull('inactivity_warned_at')
            ->where(fn (Builder $q) => $q->whereRaw(self::lastActivitySql().' > app_user.inactivity_warned_at')
                ->orWhere(fn (Builder $p) => $p->whereNot(fn (Builder $n) => self::applyCandidateFilter($n, $now))));
        if ($dryRun) {
            $this->line("Inaktive Lernende: {$reset->count()} Vorwarnung(en) würden aufgehoben.");
        } else {
            $this->line('Inaktive Lernende: '.$reset->update(['inactivity_warned_at' => null]).' Vorwarnung(en) aufgehoben (wieder aktiv oder neuer Kurszugang).');
        }

        // 2. Vorwarnen bzw. löschen.
        $candidates = self::applyCandidateFilter(User::query(), $now)
            ->select('app_user.*')->selectRaw(self::lastActivitySql().' as last_activity_at')
            ->whereRaw(self::lastActivitySql().' < ?', [$warnCutoff])
            ->orderBy('app_user.id')
            ->get();

        $toWarn = $candidates->whereNull('inactivity_warned_at');
        $toDelete = $candidates->filter(fn (User $u) => $u->inactivity_warned_at !== null
            && Carbon::parse($u->inactivity_warned_at)->lte($now->copy()->subDays($warningDays))
            && Carbon::parse($u->getAttribute('last_activity_at'))->lt($deleteCutoff));

        if ($dryRun) {
            $this->line("Inaktive Lernende: {$toWarn->count()} würden vorgewarnt, {$toDelete->count()} gelöscht (Frist {$months} Monate).");

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($toWarn as $user) {
            $failed += $this->warnLearner($user, $months, $warningDays, $now) ? 0 : 1;
        }
        foreach ($toDelete as $user) {
            $erasure->deleteAccount($user);
        }
        $this->line('Inaktive Lernende: '.($toWarn->count() - $failed)." vorgewarnt, {$toDelete->count()} gelöscht (Frist {$months} Monate).");

        if ($failed > 0) {
            $this->error("{$failed} Vorwarnung(en) konnten nicht verschickt werden und werden beim nächsten Lauf erneut versucht.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function warnLearner(User $user, int $months, int $warningDays, Carbon $now): bool
    {
        // Gelöscht wird frühestens warning_days Tage nach der Mail, sonst
        // zum Ablauf der Frist -- je nachdem, was später kommt.
        $lastActivity = Carbon::parse($user->getAttribute('last_activity_at'));
        $deleteAfter = $lastActivity->copy()->addMonths($months)->max($now->copy()->addDays($warningDays));

        // Erst vermerken, dann senden (wie RemindExpiringEntitlements).
        User::whereKey($user->id)->update(['inactivity_warned_at' => $now]);

        // Nur bei genau einer Schule in deren Namen (Absender, Link) schreiben.
        $tenantIds = DB::table('tenant_user')->where('user_id', $user->id)->pluck('tenant_id');
        $tenant = $tenantIds->count() === 1 ? Tenant::with('branding')->find($tenantIds->first()) : null;
        $context = app(TenantContext::class);
        if ($tenant) {
            $context->set($tenant);
        }
        try {
            $ok = BestEffortMail::send(fn () => $user->notify(new InactiveAccountDeletionWarning($deleteAfter, $months, $tenant)), 'Lösch-Vorwarnung '.$user->id);
        } finally {
            $context->clear();
        }
        if (! $ok) {
            User::whereKey($user->id)->update(['inactivity_warned_at' => null]);
        }

        return $ok;
    }

    /**
     * Lernende, die überhaupt unter die Frist fallen können.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function applyCandidateFilter(Builder $query, Carbon $now): Builder
    {
        return $query->where('app_user.is_superadmin', false)
            // Nie aktivierte Einladungen: eigene Frist in app:prune-personal-data.
            ->where('app_user.status', '!=', 'invited')
            ->whereNotExists(fn ($q) => $q->from('tenant_user')->whereColumn('tenant_user.user_id', 'app_user.id')->where('tenant_user.role', '!=', 'learner'))
            ->whereNotExists(fn ($q) => $q->from('entitlement')->whereColumn('entitlement.user_id', 'app_user.id')
                ->whereIn('entitlement.status', ['active', 'pending'])
                ->where(fn ($v) => $v->whereNull('entitlement.valid_until')->orWhere('entitlement.valid_until', '>', $now)));
    }

    /**
     * Letzte Anmeldung oder Lernaktivität in irgendeiner Schule (GREATEST
     * überspringt NULL). Ohne beides zählt die Kontoanlage bzw. die Aufnahme
     * in eine Schule.
     */
    private static function lastActivitySql(): string
    {
        $max = fn (string $table, string $column) => "(SELECT max({$table}.{$column}) FROM {$table} WHERE {$table}.user_id = app_user.id)";

        return 'GREATEST('.implode(', ', [
            'app_user.created_at',
            'app_user.last_login_at',
            '(SELECT to_timestamp(max(sessions.last_activity)) FROM sessions WHERE sessions.user_id = app_user.id)',
            $max('api_device_session', 'last_used_at'),
            $max('tenant_user', 'created_at'),
            $max('attempt', 'created_at'),
            $max('exam_session', 'started_at'),
            $max('exam_session', 'submitted_at'),
            $max('progress', 'updated_at'),
            $max('navigation_progress', 'updated_at'),
            $max('video_progress', 'updated_at'),
            $max('praxis_progress', 'updated_at'),
            $max('radio_training_progress', 'updated_at'),
        ]).')';
    }
}
