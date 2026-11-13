<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * app:anonymize-database (Staging): ersetzt personenbezogene Daten. Der Test
 * läuft komplett in einer Transaktion der Owner-Verbindung und rollt sie am
 * Ende zurück -- die gemeinsam genutzte Testdatenbank bleibt unverändert.
 */
class AnonymizeDatabaseTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('pgsql_admin')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->rollBack();
        $this->createdTenantIds = [];
        $this->createdUserIds = [];
        parent::tearDown();
    }

    public function test_it_refuses_in_production_and_without_force(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['email' => 'echt@schule.test']);

        $this->artisan('app:anonymize-database')->assertFailed()->expectsOutputToContain('--force');

        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->artisan('app:anonymize-database --force')->assertFailed()->expectsOutputToContain('APP_ENV=production');
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame('echt@schule.test', $this->onAdmin(fn () => User::find($user->id)->email));
    }

    public function test_it_replaces_personal_data_and_invalidates_passwords_and_tokens(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', [
            'email' => 'anna.echt@schule.test', 'display_name' => 'Anna Echt', 'first_name' => 'Anna', 'last_name' => 'Echt',
            'phone' => '+49 170 1234567', 'street' => 'Hafenstraße 5', 'postal_code' => '24103', 'city' => 'Kiel', 'birthdate' => '1999-05-17',
            'two_factor_secret' => 'geheim', 'remember_token' => 'token123',
        ]);
        $this->onAdmin(function () use ($tenant, $learner) {
            TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'lernen.echte-schule.test', 'support_email' => 'info@echte-schule.test', 'contact_first_name' => 'Hein', 'phone' => '+49 431 999']);
            TenantInvitation::create(['tenant_id' => $tenant->id, 'email' => 'eingeladen@echt.test', 'name' => 'Erika Echt', 'role' => 'learner', 'invited_by_user_id' => $learner->id]);
            DB::table('password_reset_tokens')->insert(['email' => 'anna.echt@schule.test', 'token' => 'x', 'created_at' => now()]);
            DB::table('audit_log')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'action' => 'test', 'entity_type' => 'app_user',
                'after_data' => json_encode(['email' => 'anna.echt@schule.test']), 'created_at' => now()]);
        });

        $this->artisan('app:anonymize-database --force')->assertSuccessful()->expectsOutputToContain('app:create-superadmin');

        $this->onAdmin(function () use ($tenant, $learner) {
            $user = User::find($learner->id);
            $this->assertSame('nutzer-'.str_replace('-', '', $learner->id).'@example.invalid', $user->email);
            $this->assertNotSame('Anna Echt', $user->display_name);
            $this->assertSame($user->first_name.' '.$user->last_name, $user->display_name);
            $this->assertStringStartsWith('+49 30 ', $user->phone);
            $this->assertNull($user->two_factor_secret);
            $this->assertNull($user->remember_token);
            $this->assertFalse(Hash::check('password', $user->password), 'altes Passwort ungültig');

            $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->first();
            $this->assertStringStartsWith('Musterweg ', $membership->street);
            $this->assertSame('Musterstadt', $membership->city);
            $this->assertNotSame('1999-05-17', $membership->birthdate?->toDateString());

            $branding = TenantBranding::find($tenant->id);
            $this->assertNull($branding->custom_domain);
            $this->assertStringEndsWith('@example.invalid', $branding->support_email);
            $this->assertNotSame('Hein', $branding->contact_first_name);

            $invitation = TenantInvitation::where('tenant_id', $tenant->id)->first();
            $this->assertStringEndsWith('@example.invalid', $invitation->email);
            $this->assertNotSame('Erika Echt', $invitation->name);

            $this->assertSame(0, DB::table('password_reset_tokens')->count());
            $this->assertSame(0, DB::table('sessions')->count());
            $this->assertFalse(DB::table('audit_log')->where('tenant_id', $tenant->id)->whereNotNull('after_data')->exists());
            $this->assertSame(0, DB::table('app_user')->where('email', 'not like', '%@example.invalid')->count(), 'keine echte Adresse übrig');
        });
    }
}
