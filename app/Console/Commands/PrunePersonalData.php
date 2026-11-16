<?php

namespace App\Console\Commands;

use App\Models\PraxisFormSubmission;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Services\InvitationAcceptance;
use App\Services\LearningGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * DSGVO-Review D3: es gab keinerlei Löschfristen für audit_log, attempt
 * und abgelaufene Sessions. Die konkreten Fristen für audit_log/attempt
 * sind bewusst in config/data_retention.php als deaktivierte Platzhalter
 * hinterlegt, bis sie bewusst geprüft (ggf. mit Rechtsberatung, u. a.
 * wegen möglicher Nachweispflichten für ausgestellte Bootsführerscheine)
 * und per .env aktiviert wurden. Läuft mandantenübergreifend über die
 * Owner-Verbindung, analog zu Migrationen/Seedern/Superadmin.
 */
class PrunePersonalData extends Command
{
    protected $signature = 'app:prune-personal-data
        {--dry-run : Nur anzeigen, wie viele Zeilen betroffen wären, nichts löschen}
        {--mobile-api-only : Nur die Reste der Mobile-API (Sitzungen, Idempotenz, Exporte, Löschanträge, Webansicht-Codes, Gruppeneinladungen) -- läuft stündlich}';

    protected $description = 'Löscht abgelaufene Sessions, technische Reste und nie angenommene Schul-Einladungen sowie (falls aktiviert) Audit-Log, Lern-Attempts, erledigte Shop-Ereignisse, Praxis-Angaben, Willkommensbox-Adressen und alte Anmelde-Geräte jenseits der konfigurierten Frist.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $dryRun = $this->option('dry-run');

        if ($this->option('mobile-api-only')) {
            $this->pruneMobileApi($dryRun);

            return self::SUCCESS;
        }

        $this->pruneSessions($dryRun);
        // Review-Runde 11: erst die nie aktivierten Konten -- die Prüfung
        // „vor Kurzem neu eingeladen“ liest die Einladungs-Links, die das
        // Aufräumen der technischen Reste danach löscht.
        $this->pruneUnclaimedAccounts($dryRun);
        $this->pruneTenantInvitations($dryRun);
        $this->pruneTechnicalLeftovers($dryRun);
        $this->pruneMobileApi($dryRun);
        $this->pruneCommerceEvents($dryRun);
        $this->anonymizePraxisSubmissions($dryRun);
        $this->removeWillkommensboxAddresses($dryRun);
        $this->pruneKnownLoginDevices($dryRun);
        // audit_log zuletzt: die Schritte davor leeren dort noch Kopien.
        $this->pruneTable('audit_log', 'created_at', 'audit_log', $dryRun);
        $this->pruneTable('attempt', 'created_at', 'attempt', $dryRun);

        return self::SUCCESS;
    }

    private function pruneSessions(bool $dryRun): void
    {
        $config = config('data_retention.sessions');

        if (! $config['enabled']) {
            $this->line('Sessions: Aufräumen deaktiviert (DATA_RETENTION_SESSIONS_ENABLED).');

            return;
        }

        $cutoff = now()->subDays($config['days'])->timestamp;
        $query = DB::table('sessions')->where('last_activity', '<', $cutoff);

        if ($dryRun) {
            $this->line("Sessions: {$query->count()} Zeile(n) älter als {$config['days']} Tage würden gelöscht.");

            return;
        }

        $deleted = $query->delete();
        $this->line("Sessions: {$deleted} abgelaufene Zeile(n) gelöscht.");
    }

    /**
     * Abgelaufene Cache-Zeilen (darunter Sperrzähler mit E-Mail und IP aus
     * dem Login-Throttle -- der Datenbank-Cache räumt sie nie selbst ab) und
     * abgelaufene Passwort-Reset-Tokens.
     */
    private function pruneTechnicalLeftovers(bool $dryRun): void
    {
        $cache = Schema::hasTable('cache') ? DB::table('cache')->where('expiration', '<', now()->timestamp) : null;
        $resetMinutes = (int) config('auth.passwords.users.expire', 60);
        $resets = DB::table('password_reset_tokens')->where('created_at', '<', now()->subMinutes($resetMinutes));
        // Einladungs-Links liegen in einer eigenen Tabelle mit eigener Frist (7 Tage).
        // Review-Runde 12: die (abgelaufene) Zeile bleibt so lange wie die Frist
        // für nie aktivierte Konten -- sie ist der Nachweis „vor Kurzem neu
        // eingeladen“ (pruneUnclaimedAccounts); vorher schützte ein neu
        // verschickter Link nur noch bis zum nächsten täglichen Lauf.
        $inviteMinutes = (int) config('auth.passwords.invites.expire', 60 * 24 * 7);
        if (config('data_retention.unclaimed_accounts.enabled')) {
            $inviteMinutes = max($inviteMinutes, (int) config('data_retention.unclaimed_accounts.days') * 24 * 60);
        }
        $invites = Schema::hasTable('account_setup_tokens')
            ? DB::table('account_setup_tokens')->where('created_at', '<', now()->subMinutes($inviteMinutes)) : null;
        // Shop-Verknüpfungscodes (Einmalcodes, 15 Minuten gültig): nach Ablauf
        // ohne Zweck -- die Verknüpfung selbst steht am Shopkonto und im
        // Protokoll. Einen Tag Puffer wie bei den Webansicht-Codes.
        $linkCodes = Schema::hasTable('commerce_link_code')
            ? DB::table('commerce_link_code')->where('expires_at', '<', now()->subDay()) : null;

        if ($dryRun) {
            $this->line('Cache: '.($cache?->count() ?? 0).' abgelaufene Zeile(n), Passwort-Reset-Tokens: '.$resets->count().', Einladungs-Links: '.($invites?->count() ?? 0)
                .', Shop-Verknüpfungscodes: '.($linkCodes?->count() ?? 0).' abgelaufen -- würden gelöscht.');

            return;
        }

        $this->line('Cache: '.($cache?->delete() ?? 0).' abgelaufene Zeile(n) gelöscht, Passwort-Reset-Tokens: '.$resets->delete().', Einladungs-Links: '.($invites?->delete() ?? 0)
            .', Shop-Verknüpfungscodes: '.($linkCodes?->delete() ?? 0).' gelöscht.');
    }

    /**
     * Mobile-API: beendete/abgelaufene Gerätesitzungen (nach 30 Tagen Frist
     * für Rückfragen), Idempotenz-Schlüssel nach ihrer Gültigkeit,
     * abgelaufene Datenexporte samt Datei, abgelaufene Löschanträge und
     * Einmal-Codes der Webansicht.
     */
    private function pruneMobileApi(bool $dryRun): void
    {
        if (! Schema::hasTable('api_device_session')) {
            return;
        }
        $sessions = DB::table('api_device_session')->where(fn ($q) => $q
            ->where('revoked_at', '<', now()->subDays(30))
            ->orWhere('refresh_expires_at', '<', now()->subDays(30))
            ->orWhere('max_expires_at', '<', now()->subDays(30)));
        $keys = DB::table('api_idempotency_key')->where('created_at', '<', now()->subHours((int) config('mobile_api.idempotency_hours', 24)));
        $exports = DB::table('api_data_export')->where(fn ($q) => $q->where('expires_at', '<', now())
            ->orWhere(fn ($q) => $q->whereNull('expires_at')->where('created_at', '<', now()->subDay())));
        // Abgelaufene Lerngruppen-Einladungen: Adresse und Code entfernen.
        $groupInvites = DB::table('learning_group_member')->where('status', 'invited')
            ->where('invited_at', '<', now()->subDays(LearningGroupService::INVITE_DAYS));

        // Abgelaufene Löschanträge (Bestätigung nicht mehr möglich) und
        // Einmal-Codes der Webansicht (60 Sekunden gültig) nach einem Tag.
        $deletionRequests = DB::table('api_account_deletion_request')->where('expires_at', '<', now());
        $webLogins = DB::table('api_mobile_web_session')->where('expires_at', '<', now()->subDay());

        if ($dryRun) {
            $this->line("App-Sitzungen: {$sessions->count()}, Idempotenz-Schlüssel: {$keys->count()}, Datenexporte: {$exports->count()}, Löschanträge: {$deletionRequests->count()}, Webansicht-Codes: {$webLogins->count()}, Gruppeneinladungen: {$groupInvites->count()} -- würden gelöscht.");

            return;
        }

        // Dateien nach Alter statt über die Zeilen: auch die eines inzwischen
        // gelöschten Kontos (Zeile per Fremdschlüssel schon weg) verschwinden.
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('mobile_api.export_ttl_minutes', 60))->getTimestamp();
        foreach ($disk->files('api-exports') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
        // Webansicht-Codes zuerst: sie hängen an den Gerätesitzungen.
        $webLoginCount = $webLogins->delete();
        $expiredInvites = $groupInvites->update(['status' => 'expired', 'email' => null, 'invite_token' => null, 'updated_at' => now()]);
        $this->line('App-Sitzungen: '.$sessions->delete().', Idempotenz-Schlüssel: '.$keys->delete().', Datenexporte: '.$exports->delete()
            .', Löschanträge: '.$deletionRequests->delete().', Webansicht-Codes: '.$webLoginCount.', Gruppeneinladungen: '.$expiredInvites.' gelöscht.');
    }

    /** Nie angenommene Einladungs-Konten (nie ein Passwort festgelegt) nach der Frist löschen. */
    private function pruneUnclaimedAccounts(bool $dryRun): void
    {
        $config = config('data_retention.unclaimed_accounts');
        if (! ($config['enabled'] ?? false)) {
            $this->line('Nie aktivierte Konten: Aufräumen deaktiviert (DATA_RETENTION_UNCLAIMED_ACCOUNTS_ENABLED).');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);
        $users = User::query()->where('is_superadmin', false)->where('created_at', '<', $cutoff)
            // Review-Runde 9: nur nie aktivierte Einladungs-Konten -- ein
            // aktives Konto mit (z. B. nach Adressänderung) unbestätigter
            // E-Mail bleibt; ein vor Kurzem neu verschickter Link zählt als
            // neue Frist.
            ->where('status', 'invited')
            ->whereNotExists(fn ($q) => $q->from('account_setup_tokens')->whereColumn('account_setup_tokens.email', 'app_user.email')->where('account_setup_tokens.created_at', '>=', $cutoff))
            // Nie löschen: Konten mit (bezahltem/zugewiesenem) Kurszugang und
            // Team-Mitglieder einer Schule -- dort entscheidet die Schule.
            ->whereNotExists(fn ($q) => $q->from('entitlement')->whereColumn('entitlement.user_id', 'app_user.id'))
            ->whereNotExists(fn ($q) => $q->from('tenant_user')->whereColumn('tenant_user.user_id', 'app_user.id')->where('tenant_user.role', '!=', 'learner'))
            // Review-Runde 11: auch nicht löschen, wenn eine Schule das Konto
            // innerhalb der Frist (neu) aufgenommen oder eingeladen hat oder
            // schon etwas daran hängt (Kauf, Willkommensbox, Praxis-Anmeldung).
            ->whereNotExists(fn ($q) => $q->from('tenant_user')->whereColumn('tenant_user.user_id', 'app_user.id')->where('tenant_user.created_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->from('tenant_invitation')->whereColumn('tenant_invitation.email', 'app_user.email')->where('tenant_invitation.created_at', '>=', $cutoff))
            ->whereNotExists(fn ($q) => $q->from('product_purchase')->whereColumn('product_purchase.user_id', 'app_user.id'))
            ->whereNotExists(fn ($q) => $q->from('willkommensbox_order')->whereColumn('willkommensbox_order.user_id', 'app_user.id'))
            ->whereNotExists(fn ($q) => $q->from('praxis_form_submission')->whereColumn('praxis_form_submission.user_id', 'app_user.id'))
            // Shop-Registrierung in der B2B-Prüfung: das Konto wartet auf die Freigabe
            // -- aber nicht unbegrenzt (offene Registrierung, sonst ließe sich jede
            // Adresse dauerhaft belegen). Verknüpfte Shopkonten bleiben. (Betrifft nur
            // ältere Registrierungen: neue legen erst bei der Freigabe ein App-Konto an.)
            ->whereNotExists(fn ($q) => $q->from('commerce_shop_account')->whereColumn('commerce_shop_account.user_id', 'app_user.id')
                ->where(fn ($w) => $w->where('commerce_shop_account.status', 'linked')
                    ->orWhere(fn ($p) => $p->where('commerce_shop_account.status', 'pending_review')->where('commerce_shop_account.created_at', '>', now()->subDays(180)))))
            ->get();

        if ($dryRun) {
            $this->line("Nie aktivierte Konten: {$users->count()} älter als {$config['days']} Tage würden gelöscht.");

            return;
        }

        $erasure = app(AccountErasureService::class);
        foreach ($users as $user) {
            $erasure->deleteAccount($user);
        }
        $this->line("Nie aktivierte Konten: {$users->count()} gelöscht.");
    }

    /**
     * Löschkonzept Nr. 31: nie angenommene Einladungen einer Schule
     * (tenant_invitation) N Tage nach dem Anlegen löschen -- abgelaufen
     * sind sie schon nach InvitationAcceptance::VALID_DAYS. Mindestens so
     * lange wie die Frist für nie aktivierte Konten, solange die aktiv ist:
     * dort schützt eine Einladung innerhalb der Frist das Konto. Angenommene
     * Einladungen bleiben.
     */
    /** Wirksame Frist für nie angenommene Schul-Einladungen in Tagen (null = aus), auch für die Datenschutzerklärung. */
    public static function tenantInvitationDays(): ?int
    {
        $days = config('data_retention.tenant_invitations.days');
        if (! $days) {
            return null;
        }
        $days = max((int) $days, InvitationAcceptance::VALID_DAYS);
        if (config('data_retention.unclaimed_accounts.enabled')) {
            $days = max($days, (int) config('data_retention.unclaimed_accounts.days'));
        }

        return $days;
    }

    private function pruneTenantInvitations(bool $dryRun): void
    {
        $days = self::tenantInvitationDays();
        if (! $days) {
            $this->line('Schul-Einladungen: Aufräumen deaktiviert (DATA_RETENTION_TENANT_INVITATION_DAYS).');

            return;
        }

        $query = DB::table('tenant_invitation')->whereNull('accepted_at')->where('created_at', '<', now()->subDays($days));

        if ($dryRun) {
            $this->line("Schul-Einladungen: {$query->count()} nie angenommene älter als {$days} Tage würden gelöscht.");

            return;
        }

        $this->line("Schul-Einladungen: {$query->delete()} nie angenommene gelöscht.");
    }

    /**
     * Löschkonzept Nr. 20: erledigte Shop-Ereignisse nach der Frist löschen.
     * Nie Ausgangs-Ereignisse, die noch warten („pending“), gerade gesendet
     * werden („sending“) oder endgültig fehlgeschlagen sind („failed“, im
     * Superadmin-Bereich erneut zu versuchen), und nie Storno-Ereignisse:
     * ShopOrders::void() erkennt daran einen wiederholten Storno-Aufruf --
     * ohne sie sperrte eine Wiederholung mit Menge erneut Codes.
     */
    private function pruneCommerceEvents(bool $dryRun): void
    {
        $days = config('data_retention.commerce_events.days');
        if (! $days || ! Schema::hasTable('commerce_event')) {
            $this->line('Shop-Ereignisse: keine Frist festgelegt (DATA_RETENTION_COMMERCE_EVENT_DAYS).');

            return;
        }

        $cutoff = now()->subDays((int) $days);
        $query = fn () => DB::table('commerce_event')->where('status', 'done')->where('type', '!=', 'batch.voided')
            ->whereRaw('COALESCE(processed_at, created_at) < ?', [$cutoff]);

        if ($dryRun) {
            $this->line('Shop-Ereignisse: '.$query()->count()." erledigte älter als {$days} Tage würden gelöscht.");

            return;
        }

        $chunk = max((int) config('data_retention.prune_chunk', 5000), 1);
        $deleted = 0;
        do {
            $ids = $query()->limit($chunk)->pluck('id');
            $count = $ids->isEmpty() ? 0 : DB::table('commerce_event')->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($ids->count() >= $chunk);
        $this->line("Shop-Ereignisse: {$deleted} erledigte gelöscht.");
    }

    /**
     * Löschkonzept Nr. 16: Formularangaben einer Praxis-Anmeldung N Monate
     * nach dem letzten Bezug entfernen. Maßgeblich ist der späteste Zeitpunkt
     * aus letzter Änderung der Anmeldung, fixiertem Prüfungstermin und allen
     * Terminen (Beginn, Ende, letzte Rückmeldung) -- ein noch anstehender
     * Termin schützt die Anmeldung also immer, ebenso ein aktiver Zugang
     * der Person zum Kurs der Anmeldung. Die Zeile bleibt (Zählungen in
     * der Übersicht, „bereits angemeldet“, Termine mit Zeitpunkten); geleert
     * werden die Antworten (data), die Freitext-Rückmeldungen zu den
     * Terminen und die Kopien davon im Änderungsprotokoll.
     */
    private function anonymizePraxisSubmissions(bool $dryRun): void
    {
        $months = config('data_retention.praxis_submissions.months');
        if (! $months) {
            $this->line('Praxis-Anmeldungen: keine Frist festgelegt (DATA_RETENTION_PRAXIS_SUBMISSION_MONTHS).');

            return;
        }

        $cutoff = now()->subMonths((int) $months);
        $practiceOnly = PraxisFormSubmission::PRACTICE_ONLY_KEY;
        $marker = self::PRAXIS_REMOVED_KEY;
        $ids = DB::table('praxis_form_submission as s')
            ->whereRaw('GREATEST(s.updated_at, s.created_at, s.confirmed_exam_date, (
                SELECT MAX(GREATEST(a.exam_date, a.ends_at, a.responded_at, a.updated_at))
                FROM praxis_exam_appointment a WHERE a.praxis_form_submission_id = s.id)) < ?', [$cutoff])
            // Review: solange die Person noch einen aktiven Zugang zu diesem
            // Kurs hat, bleibt die Anmeldung vollständig (sie kann sich z. B.
            // nach einer nicht bestandenen Prüfung erneut anmelden).
            ->whereNotExists(fn ($e) => $e->from('entitlement as en')->whereColumn('en.tenant_id', 's.tenant_id')
                ->whereColumn('en.user_id', 's.user_id')->whereColumn('en.course_id', 's.course_id')->where('en.status', 'active')
                ->where(fn ($q) => $q->whereNull('en.valid_until')->orWhere('en.valid_until', '>', now())))
            // Nur, wo noch etwas zu entfernen ist (Kennzeichen zählen nicht).
            ->where(fn ($q) => $q->whereRaw("(s.data - ? - ?) <> '{}'::jsonb", [$practiceOnly, $marker])
                ->orWhereExists(fn ($e) => $e->from('praxis_exam_appointment as c')->whereColumn('c.praxis_form_submission_id', 's.id')->whereNotNull('c.response_comment')))
            ->pluck('s.id');

        if ($dryRun) {
            $this->line("Praxis-Anmeldungen: {$ids->count()} älter als {$months} Monate würden anonymisiert.");

            return;
        }

        foreach ($ids->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk, $practiceOnly, $marker) {
                $appointmentIds = DB::table('praxis_exam_appointment')->whereIn('praxis_form_submission_id', $chunk)->pluck('id');
                // Reine Praxisstunden-Behälter bleiben als solche erkennbar.
                DB::table('praxis_form_submission')->whereIn('id', $chunk)->update([
                    'data' => DB::raw("jsonb_strip_nulls(jsonb_build_object('{$practiceOnly}', data->'{$practiceOnly}', '{$marker}', '".now()->toDateString()."'::text))"),
                ]);
                DB::table('praxis_exam_appointment')->whereIn('id', $appointmentIds)->update(['response_comment' => null]);
                $this->clearAuditCopies('praxis_form_submission', $chunk);
                $this->clearAuditCopies('praxis_exam_appointment', $appointmentIds);
            });
        }
        $this->line("Praxis-Anmeldungen: {$ids->count()} anonymisiert.");
    }

    /** Kennzeichen in praxis_form_submission.data: Formularangaben nach der Frist entfernt (Datum). */
    public const PRAXIS_REMOVED_KEY = PraxisFormSubmission::ANSWERS_REMOVED_KEY;

    /**
     * Löschkonzept Nr. 17: Lieferadresse einer Willkommensbox N Tage nach dem
     * Versand entfernen -- nie vor dem Versand. Die Bestellung bleibt
     * (Versandzahlen, „schon bestellt“).
     */
    private function removeWillkommensboxAddresses(bool $dryRun): void
    {
        $days = config('data_retention.willkommensbox_addresses.days');
        if (! $days) {
            $this->line('Willkommensbox-Adressen: keine Frist festgelegt (DATA_RETENTION_WILLKOMMENSBOX_ADDRESS_DAYS).');

            return;
        }

        $ids = DB::table('willkommensbox_order')->whereNotNull('shipped_at')->where('shipped_at', '<', now()->subDays((int) $days))
            ->where(fn ($q) => $q->whereNotNull('street')->orWhereNotNull('postal_code')->orWhereNotNull('city')
                ->orWhereNotNull('country')->orWhereNotNull('note')->orWhereNotNull('tracking_number'))
            ->pluck('id');

        if ($dryRun) {
            $this->line("Willkommensbox-Adressen: {$ids->count()} versendet vor mehr als {$days} Tagen würden entfernt.");

            return;
        }

        foreach ($ids->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk) {
                DB::table('willkommensbox_order')->whereIn('id', $chunk)->update([
                    'street' => null, 'postal_code' => null, 'city' => null, 'country' => null, 'note' => null, 'tracking_number' => null,
                ]);
                // WillkommensboxOrderController::ship() protokolliert die Adresse mit.
                $this->clearAuditCopies('willkommensbox_order', $chunk);
            });
        }
        $this->line("Willkommensbox-Adressen: {$ids->count()} entfernt.");
    }

    /**
     * Löschkonzept Nr. 18: lange nicht gesehene Geräte vergessen. Das zuletzt
     * gesehene Gerät je Konto bleibt: ohne jedes bekannte Gerät gilt die
     * nächste Anmeldung als erstes Gerät und löst keinen Hinweis aus
     * (NewLoginDeviceNotifier) -- auch nicht die eines Fremden.
     */
    private function pruneKnownLoginDevices(bool $dryRun): void
    {
        $months = config('data_retention.known_login_devices.months');
        if (! $months || ! Schema::hasTable('known_login_device')) {
            $this->line('Gerätewiedererkennung: keine Frist festgelegt (DATA_RETENTION_KNOWN_LOGIN_DEVICE_MONTHS).');

            return;
        }

        $query = DB::table('known_login_device as k')->where('k.last_seen_at', '<', now()->subMonths((int) $months))
            ->whereExists(fn ($q) => $q->from('known_login_device as newer')->whereColumn('newer.user_id', 'k.user_id')
                ->where(fn ($w) => $w->whereColumn('newer.last_seen_at', '>', 'k.last_seen_at')
                    ->orWhere(fn ($t) => $t->whereColumn('newer.last_seen_at', 'k.last_seen_at')->whereColumn('newer.id', '>', 'k.id'))));

        if ($dryRun) {
            $this->line("Gerätewiedererkennung: {$query->count()} Gerät(e) seit mehr als {$months} Monaten nicht gesehen -- würden gelöscht.");

            return;
        }

        $deleted = DB::table('known_login_device')->whereIn('id', $query->select('k.id'))->delete();
        $this->line("Gerätewiedererkennung: {$deleted} Gerät(e) gelöscht.");
    }

    /**
     * Kopien im Änderungsprotokoll leeren (wie beim Löschen einer Anmeldung):
     * der Eintrag selbst (Aktion, Zeitpunkt) bleibt als Nachweis.
     *
     * @param  iterable<string>  $entityIds
     */
    private function clearAuditCopies(string $entityType, iterable $entityIds): void
    {
        $ids = collect($entityIds)->values()->all();
        if ($ids === []) {
            return;
        }
        DB::table('audit_log')->where('entity_type', $entityType)->whereIn('entity_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('before_data')->orWhereNotNull('after_data'))
            ->update(['before_data' => null, 'after_data' => null]);
    }

    private function pruneTable(string $configKey, string $dateColumn, string $table, bool $dryRun): void
    {
        $config = config("data_retention.{$configKey}");

        if (! $config['enabled']) {
            $this->line("{$table}: Aufräumen deaktiviert (DATA_RETENTION_".strtoupper($configKey).'_ENABLED -- Frist bewusst zu prüfen, siehe config/data_retention.php).');

            return;
        }

        $cutoff = now()->subMonths($config['months']);
        $query = DB::table($table)->where($dateColumn, '<', $cutoff);

        if ($dryRun) {
            $this->line("{$table}: {$query->count()} Zeile(n) älter als {$config['months']} Monate würden gelöscht.");

            return;
        }

        // Audit ARC-4: in Blöcken (über ctid, jede Anweisung eine eigene
        // kurze Transaktion) statt in einem einzigen DELETE über Millionen
        // Zeilen -- das hielt Sperren und WAL lange und blähte die Tabelle.
        $chunk = max((int) config('data_retention.prune_chunk', 5000), 1);
        $deleted = 0;
        do {
            $count = DB::delete(
                "DELETE FROM {$table} WHERE ctid = ANY(ARRAY(SELECT ctid FROM {$table} WHERE {$dateColumn} < ? LIMIT {$chunk}))",
                [$cutoff]
            );
            $deleted += $count;
        } while ($count >= $chunk);
        $this->line("{$table}: {$deleted} Zeile(n) jenseits der Aufbewahrungsfrist gelöscht.");
    }
}
