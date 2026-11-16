<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Plattform-Einstellung „2FA-Pflicht für neue Schulen“ (Standard an): beim
 * Anlegen einer Schule übernimmt tenant_branding.require_team_two_factor
 * diesen Wert; bestehende Schulen bleiben unverändert.
 */
class NewTenantTeamTwoFactorDefaultTest extends TestCase
{
    use InteractsWithTenants;

    private ?bool $originalSetting = null;

    private array $adminEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->originalSetting = $this->onAdmin(fn () => PlatformSetting::current()->new_tenant_requires_team_two_factor);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            PlatformSetting::current()->update(['new_tenant_requires_team_two_factor' => $this->originalSetting ?? true]);
            foreach ($this->adminEmails as $email) {
                DB::table('account_setup_tokens')->where('email', $email)->delete();
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function createSchool(User $superadmin): Tenant
    {
        $slug = 'zfa-'.Str::lower(Str::random(8));
        $email = $slug.'@example.test';
        $this->adminEmails[] = $email;
        $this->actingAs($superadmin)->post(route('superadmin.tenants.store'), [
            'slug' => $slug, 'name' => '2FA Schule', 'status' => 'active',
            'admin_first_name' => 'Ina', 'admin_last_name' => 'Inhaber', 'admin_email' => $email,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->onAdmin(function () use ($slug, $email) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();
            $this->createdTenantIds[] = $tenant->id;
            $this->createdUserIds[] = User::where('email', $email)->value('id');

            return $tenant;
        });
    }

    public function test_new_schools_require_team_two_factor_by_default(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update(['new_tenant_requires_team_two_factor' => true]));
        $superadmin = $this->createSuperAdmin();
        $this->createdUserIds[] = $superadmin->id;

        $tenant = $this->createSchool($superadmin);

        $this->assertTrue($this->onAdmin(fn () => TenantBranding::find($tenant->id)->require_team_two_factor));
    }

    public function test_the_setting_can_be_switched_off_on_the_settings_page_and_existing_schools_stay_unchanged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $this->createdUserIds[] = $superadmin->id;
        $existing = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $existing->id)->update(['require_team_two_factor' => true]));

        $this->actingAs($superadmin)->get(route('superadmin.settings.edit'))->assertOk()
            ->assertSee('2FA-Pflicht für neue Schulen')->assertSee('Bestehende Schulen bleiben unverändert.');

        $settings = $this->onAdmin(fn () => PlatformSetting::current());
        $this->actingAs($superadmin)->patch(route('superadmin.settings.update'), [
            'site_name' => $settings->site_name,
            // Checkbox nicht gesendet = aus.
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->onAdmin(fn () => PlatformSetting::current()->new_tenant_requires_team_two_factor));

        $tenant = $this->createSchool($superadmin);
        $this->assertFalse($this->onAdmin(fn () => TenantBranding::find($tenant->id)->require_team_two_factor));
        $this->assertTrue($this->onAdmin(fn () => TenantBranding::find($existing->id)->require_team_two_factor));

        $this->actingAs($superadmin)->patch(route('superadmin.settings.update'), [
            'site_name' => $settings->site_name,
            'new_tenant_requires_team_two_factor' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->onAdmin(fn () => PlatformSetting::current()->new_tenant_requires_team_two_factor));
    }
}
