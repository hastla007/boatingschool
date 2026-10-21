<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAndEntitlementSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedForTenant('mueller', 'muellerboot', [
            ['name' => 'Anna Meyer', 'email' => 'admin@bootsschule-mueller.de', 'role' => 'admin'],
        ], [
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'SBF-SEE'],
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'SBF-SEE-BIN'],
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'ADD-SEE-TO-BIN'],
            ['name' => 'Erika Schmidt', 'email' => 'erika@bootsschule-mueller.de', 'course' => 'SBF-BIN-MOTOR'],
            ['name' => 'Erika Schmidt', 'email' => 'erika@bootsschule-mueller.de', 'course' => 'ADD-BIN-TO-SEE'],
            ['name' => 'Erika Schmidt', 'email' => 'erika@bootsschule-mueller.de', 'course' => 'SBF-BIN-SEGEL'],
            ['name' => 'Erika Schmidt', 'email' => 'erika@bootsschule-mueller.de', 'course' => 'ADD-BIN-SEGEL'],
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'FKN'],
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'SKN'],
            ['name' => 'Max Mustermann', 'email' => 'max@bootsschule-mueller.de', 'course' => 'ADD-FKN-TO-SKN'],
        ]);

        $this->seedForTenant('hanse-kiel', 'hansekiel', [
            ['name' => 'Jonas Hansen', 'email' => 'admin@hanse-bootsschule-kiel.de', 'role' => 'admin'],
        ], [
            ['name' => 'Lena Petersen', 'email' => 'lena@hanse-bootsschule-kiel.de', 'course' => 'SRC'],
        ]);
    }

    private function seedForTenant(string $tenantSlug, string $passwordSeed, array $admins, array $learners): void
    {
        $tenant = Tenant::where('slug', $tenantSlug)->firstOrFail();

        foreach ($admins as $admin) {
            $user = User::firstOrCreate(
                ['email' => $admin['email']],
                // email_verified_at => now(): Demo-Zugänge sollen sich ohne
                // Zusatzschritt einloggen lassen (Sicherheitsreview H2
                // verlangt eine verifizierte E-Mail für den "verified"-
                // Middleware-Gate).
                ['display_name' => $admin['name'], 'password' => Hash::make('password'), 'status' => 'active', 'email_verified_at' => now()]
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $admin['role'], 'status' => 'active']
            );
        }

        foreach ($learners as $learner) {
            // Max Mustermann bekommt ein vollständiges Profil (Adresse +
            // Geburtsdatum), damit die bestehenden Demo-/Verifikationsläufe
            // für Praxis & Prüfung ohne Zusatzschritt weiter funktionieren --
            // die übrigen Demo-Lerner bleiben bewusst unvollständig, um das
            // neue Profil-Gate (siehe PraxisPruefungController) im Frontend
            // demonstrierbar zu lassen.
            $profile = $learner['email'] === 'max@bootsschule-mueller.de' ? [
                'street' => 'Hafenstraße 12',
                'postal_code' => '20095',
                'city' => 'Hamburg',
                'country' => 'Deutschland',
                'birthdate' => '1990-06-15',
            ] : [];

            $user = User::firstOrCreate(
                ['email' => $learner['email']],
                array_merge(['display_name' => $learner['name'], 'password' => Hash::make('password'), 'status' => 'active', 'email_verified_at' => now()], $profile)
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => 'learner', 'status' => 'active']
            );

            $course = CourseDefinition::withoutGlobalScopes()->where('code', $learner['course'])->whereNull('tenant_id')->first();

            if ($course) {
                Entitlement::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id],
                    ['status' => 'active', 'source_type' => 'manual', 'source_reference' => 'seed-demo']
                );
            }
        }
    }
}
