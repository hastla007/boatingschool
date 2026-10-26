<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * O1: is_superadmin lässt sich bislang ausschließlich über
 * Superadmin\UserController setzen -- für den allerersten Superadmin
 * einer frischen Produktivinstallation (noch keiner existiert) blieb nur
 * eine manuelle DB-Änderung per psql/tinker. Läuft wie ExpireEntitlements/
 * PrunePersonalData direkt über die Owner-Verbindung, weil ein
 * Konsolenbefehl keinen Tenant-/Superadmin-Session-Kontext hat, der
 * UseAdminDatabaseConnection sonst herstellen würde.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-superadmin {email} {--name=} {--reset-password}';

    protected $description = 'Legt einen neuen Superadmin an oder befördert ein bestehendes Konto dazu.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $email = strtolower(trim($this->argument('email')));

        $emailValidator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);
        if ($emailValidator->fails()) {
            $this->error($emailValidator->errors()->first('email'));

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // Bug-Review R3-4: email_verified_at wurde hier bisher
            // bedingungslos erzwungen, sobald es noch null war -- ein
            // Konto, dessen Adresse NIE bestätigt wurde (niemand hat je
            // bewiesen, diese Mailbox tatsächlich zu besitzen), hätte so
            // ungeprüft Superadmin-Rechte samt seines eigenen, dem
            // Betreiber dieses Befehls unbekannten Passworts erhalten.
            if (! $user->email_verified_at) {
                $this->error("{$email} hat seine E-Mail-Adresse nie bestätigt. Aus Sicherheitsgründen wird ein unbestätigtes Konto hier nicht automatisch verifiziert und zum Superadmin befördert -- erst die Bestätigung durch den tatsächlichen Besitzer sicherstellen (z. B. Passwort-Reset-Link), oder bei Bedarf ein komplett neues Superadmin-Konto mit einer anderen E-Mail-Adresse anlegen.");

                return self::FAILURE;
            }

            // Bestehendes Konto: nur befördern, Passwort nicht antasten --
            // es sei denn, explizit per --reset-password gewünscht (z. B.
            // weil das Konto noch nie ein Passwort hatte).
            if ($this->option('reset-password')) {
                $password = $this->resolvePassword();
                if ($password === null) {
                    return self::FAILURE;
                }

                $passwordValidator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
                if ($passwordValidator->fails()) {
                    $this->error($passwordValidator->errors()->first('password'));

                    return self::FAILURE;
                }

                $user->forceFill(['password' => Hash::make($password)]);
            }

            $user->forceFill([
                'is_superadmin' => true,
                'status' => 'active',
            ])->save();

            $this->info("{$email} ist jetzt Superadmin.");

            return self::SUCCESS;
        }

        // Neues Konto: Name und Passwort sind Pflicht.
        $name = $this->option('name') ?: $this->ask('Name');
        if (! $name) {
            $this->error('Für ein neues Konto wird ein Name benötigt (--name oder interaktive Eingabe).');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $passwordValidator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($passwordValidator->fails()) {
            $this->error($passwordValidator->errors()->first('password'));

            return self::FAILURE;
        }

        User::create([
            'display_name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
        ])->forceFill(['is_superadmin' => true])->save();

        $this->info("Neuer Superadmin {$email} angelegt.");

        return self::SUCCESS;
    }

    /**
     * Bug-Review R3-4: ein --password=... CLI-Argument landet unverschlüsselt
     * in der Shell-History (~/.bash_history o. ä.) und ist für die Laufzeit
     * des Prozesses jedem mit Leserechten auf die Prozessliste sichtbar
     * (z. B. "ps aux" auf einem geteilten Server) -- genau die Art von
     * Geheimnis, die eine Argumentzeile nie enthalten sollte. Interaktive
     * Eingabe über secret() (kein Echo im Terminal, landet nirgends in
     * Logs/History) ist deshalb jetzt der einzige Weg für einen Menschen.
     * Für automatisiertes/skriptgesteuertes Anlegen (z. B. beim initialen
     * Deployment) bleibt eine Umgebungsvariable die einzige Ausnahme --
     * Secrets-Verwaltung (Docker/Ansible/CI) schreibt diese nie in eine
     * interaktive Shell-History, anders als ein getippter CLI-Parameter.
     */
    private function resolvePassword(): ?string
    {
        if ($envPassword = env('SUPERADMIN_PASSWORD')) {
            return $envPassword;
        }

        $password = $this->secret('Passwort');
        $confirmation = $this->secret('Passwort bestätigen');

        if ($password !== $confirmation) {
            $this->error('Die Passwörter stimmen nicht überein.');

            return null;
        }

        return $password;
    }
}
