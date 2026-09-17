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
            ['name' => 'Erika Schmidt', 'email' => 'erika@bootsschule-mueller.de', 'course' => 'SBF-BIN-MOTOR'],
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
                ['display_name' => $admin['name'], 'password' => Hash::make('password'), 'status' => 'active']
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $admin['role'], 'status' => 'active']
            );
        }

        foreach ($learners as $learner) {
            $user = User::firstOrCreate(
                ['email' => $learner['email']],
                ['display_name' => $learner['name'], 'password' => Hash::make('password'), 'status' => 'active']
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
