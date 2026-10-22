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
    protected $signature = 'app:create-superadmin {email} {--name=} {--password=}';

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
            // Bestehendes Konto: nur befördern, Passwort nicht antasten --
            // es sei denn, explizit per --password gewünscht (z. B. weil
            // das Konto noch nie ein Passwort hatte).
            if ($this->option('password')) {
                $password = $this->option('password');

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
                'email_verified_at' => $user->email_verified_at ?? now(),
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

        $password = $this->option('password');
        if (! $password) {
            $password = $this->secret('Passwort');
            $confirmation = $this->secret('Passwort bestätigen');

            if ($password !== $confirmation) {
                $this->error('Die Passwörter stimmen nicht überein.');

                return self::FAILURE;
            }
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
}
