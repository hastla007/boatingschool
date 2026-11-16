<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Personenbezogene Daten einer Datenbank-KOPIE ersetzen (Staging mit einem
 * Dump aus der Produktion, siehe deploy/README.md „Staging“): E-Mail-
 * Adressen, Namen, Anschriften und Telefonnummern werden durch erfundene
 * Werte ersetzt, Passwörter, Zwei-Faktor-Schlüssel, Sitzungen und Tokens
 * ungültig gemacht, Freitexte und Protokoll-Inhalte geleert.
 *
 * Läuft nie mit APP_ENV=production und nur mit --force. Alles in einer
 * Transaktion über die Owner-Rolle (RLS gilt für sie nicht, es geht um
 * alle Schulen).
 */
class AnonymizeDatabase extends Command
{
    protected $signature = 'app:anonymize-database {--force : Wirklich ausführen (Daten werden unwiderruflich überschrieben)}';

    protected $description = 'Ersetzt personenbezogene Daten durch erfundene Werte (nur Staging/Test, nie Produktion).';

    private const FIRST_NAMES = ['Anna', 'Ben', 'Clara', 'David', 'Emma', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas', 'Klara', 'Lukas', 'Mia', 'Noah', 'Paula', 'Quirin', 'Romy', 'Simon', 'Tilda', 'Uwe', 'Vera', 'Wilhelm', 'Xenia', 'Yusuf', 'Zoe'];

    private const LAST_NAMES = ['Albers', 'Bauer', 'Claasen', 'Dietrich', 'Ehlers', 'Friedrichs', 'Gerdes', 'Hansen', 'Iversen', 'Jansen', 'Kröger', 'Lorenzen', 'Meyer', 'Nissen', 'Ohlsen', 'Petersen', 'Quast', 'Rasmussen', 'Schulz', 'Thomsen', 'Ulrichs', 'Voss', 'Wulf', 'Zander'];

    /** Tabellen, deren Inhalt komplett entfällt (Sitzungen, Tokens, Warteschlangen, Ausgang zum Shop). */
    private const CLEARED_TABLES = [
        'sessions', 'password_reset_tokens', 'account_setup_tokens', 'api_device_session', 'api_idempotency_key',
        'known_login_device', 'api_data_export', 'commerce_event', 'commerce_link_code',
        'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Abgebrochen: APP_ENV=production. Anonymisieren nur auf einer Kopie (Staging, Test).');

            return self::FAILURE;
        }
        $db = DB::connection('pgsql_admin');
        if (! $this->option('force')) {
            $this->error("Überschreibt personenbezogene Daten in der Datenbank „{$db->getDatabaseName()}“ unwiderruflich. Zum Ausführen --force angeben.");

            return self::FAILURE;
        }

        $this->info("Anonymisiere Datenbank „{$db->getDatabaseName()}“ ...");
        $counts = $db->transaction(fn () => $this->anonymize($db));

        $this->table(['Tabelle', 'Zeilen'], collect($counts)->map(fn ($n, $table) => [$table, $n])->values()->all());
        $this->info('Fertig. Anmelden: neues Superadmin-Konto mit php artisan app:create-superadmin anlegen (alle Passwörter sind ungültig).');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function anonymize(Connection $db): array
    {
        $first = '{'.implode(',', self::FIRST_NAMES).'}';
        $last = '{'.implode(',', self::LAST_NAMES).'}';
        // Erfundener Name/Wert je Zeile, stabil aus der ID abgeleitet.
        $pick = fn (string $array, string $key, string $salt) => "({$array}::text[])[1 + mod(abs(hashtext({$key}::text || '{$salt}')), array_length({$array}::text[], 1))]";
        $digits = fn (string $key, int $length) => "lpad(mod(abs(hashtext({$key}::text || 'n')), ".(10 ** $length).")::text, {$length}, '0')";
        $street = fn (string $key) => "'Musterweg ' || (1 + mod(abs(hashtext({$key}::text || 's')), 99))::text";
        $hex = fn (string $key) => "replace({$key}::text, '-', '')";

        $counts = [];
        // Ein einziger, unbekannter Hash für alle: niemand kann sich mit einem alten Passwort anmelden.
        $password = Hash::make(Str::random(64));
        $counts['app_user'] = $db->update(
            "UPDATE app_user u SET first_name = n.f, last_name = n.l, display_name = n.f || ' ' || n.l,
                email = 'nutzer-' || {$hex('u.id')} || '@example.invalid', pending_email = NULL, external_identity = NULL,
                phone = CASE WHEN u.phone IS NULL THEN NULL ELSE '+49 30 ' || {$digits('u.id', 7)} END,
                password = ?, remember_token = NULL,
                two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_confirmed_at = NULL
             FROM (SELECT id, {$pick('?', 'id', 'f')} AS f, {$pick('?', 'id', 'l')} AS l FROM app_user) n
             WHERE n.id = u.id",
            [$password, $first, $first, $last, $last]
        );

        $counts['tenant_user'] = $db->update(
            "UPDATE tenant_user SET
                street = CASE WHEN street IS NULL THEN NULL ELSE {$street('user_id')} END,
                postal_code = CASE WHEN postal_code IS NULL THEN NULL ELSE {$digits('user_id', 5)} END,
                city = CASE WHEN city IS NULL THEN NULL ELSE 'Musterstadt' END,
                birthdate = CASE WHEN birthdate IS NULL THEN NULL ELSE date '1970-01-01' + mod(abs(hashtext(user_id::text || 'b')), 12000) END"
        );

        $counts['tenant_invitation'] = $db->update(
            "UPDATE tenant_invitation SET email = 'einladung-' || {$hex('id')} || '@example.invalid',
                name = CASE WHEN name IS NULL THEN NULL ELSE {$pick('?', 'id', 'f')} || ' ' || {$pick('?', 'id', 'l')} END",
            [$first, $first, $last, $last]
        );

        $counts['willkommensbox_order'] = $db->update(
            "UPDATE willkommensbox_order SET street = {$street('id')}, postal_code = {$digits('id', 5)}, city = 'Musterstadt', note = NULL, tracking_number = NULL"
        );

        // Kontaktdaten der Schulen; eigene Domains entfallen (Staging darf
        // keine Zertifikate für die echten Domains der Schulen anfordern).
        $counts['tenant_branding'] = $db->update(
            "UPDATE tenant_branding SET custom_domain = NULL,
                contact_first_name = CASE WHEN contact_first_name IS NULL THEN NULL ELSE {$pick('?', 'tenant_id', 'f')} END,
                contact_last_name = CASE WHEN contact_last_name IS NULL THEN NULL ELSE {$pick('?', 'tenant_id', 'l')} END,
                representative = CASE WHEN representative IS NULL THEN NULL ELSE 'Max Mustermann' END,
                support_email = CASE WHEN support_email IS NULL THEN NULL ELSE 'schule-' || {$hex('tenant_id')} || '@example.invalid' END,
                phone = CASE WHEN phone IS NULL THEN NULL ELSE '+49 40 ' || {$digits('tenant_id', 7)} END,
                whatsapp_phone = CASE WHEN whatsapp_phone IS NULL THEN NULL ELSE '+49 151 ' || {$digits('tenant_id', 7)} END,
                street = CASE WHEN street IS NULL THEN NULL ELSE {$street('tenant_id')} END,
                postal_code = CASE WHEN postal_code IS NULL THEN NULL ELSE {$digits('tenant_id', 5)} END,
                city = CASE WHEN city IS NULL THEN NULL ELSE 'Musterstadt' END",
            [$first, $first, $last, $last]
        );

        $counts['commerce_shop_account'] = $db->update(
            "UPDATE commerce_shop_account SET email = 'shop-' || {$hex('id')} || '@example.invalid',
                contact_name = CASE WHEN contact_name IS NULL THEN NULL ELSE 'Max Mustermann' END,
                review_note = NULL, provisioning_error = NULL"
        );

        $counts['platform_setting'] = $db->update(
            "UPDATE platform_setting SET support_email = CASE WHEN support_email IS NULL THEN NULL ELSE 'support@example.invalid' END"
        );

        // Freitexte und Formulardaten: Struktur bleibt, Texte entfallen.
        $counts['praxis_form_submission'] = $db->update(
            "UPDATE praxis_form_submission SET data = COALESCE((
                SELECT jsonb_object_agg(key, CASE WHEN jsonb_typeof(value) = 'string' THEN to_jsonb('anonymisiert'::text) ELSE value END)
                FROM jsonb_each(data)), '{}'::jsonb)
             WHERE jsonb_typeof(data) = 'object'"
        );
        $counts['praxis_exam_appointment'] = $db->update('UPDATE praxis_exam_appointment SET response_comment = NULL, online_meeting_url = NULL');
        $counts['audit_log'] = $db->update('UPDATE audit_log SET before_data = NULL, after_data = NULL WHERE before_data IS NOT NULL OR after_data IS NOT NULL');

        foreach (self::CLEARED_TABLES as $table) {
            // DELETE statt TRUNCATE: läuft in der Transaktion ohne exklusive Tabellensperre.
            $counts[$table] = $db->table($table)->delete();
        }

        return $counts;
    }
}
