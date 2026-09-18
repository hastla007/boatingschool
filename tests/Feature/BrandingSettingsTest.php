<?php

namespace Tests\Feature;

use App\Mail\VerifySupportEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_new_tenants_default_to_a_fifty_percent_exam_readiness_threshold(): void
    {
        $tenant = $this->createTestTenant();

        $this->assertSame(50, $tenant->branding->fresh()->exam_readiness_threshold_percent);
    }

    public function test_a_school_admin_can_configure_the_exam_readiness_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 75,
        ]);

        $response->assertRedirect();
        $this->assertSame(75, $tenant->branding->fresh()->exam_readiness_threshold_percent);
    }

    public function test_exam_readiness_threshold_must_be_a_percentage(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 150,
        ]);

        $response->assertSessionHasErrors('exam_readiness_threshold_percent');
    }

    public function test_a_school_admin_can_configure_contact_address_and_website(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'contact_first_name' => 'Anna',
            'contact_last_name' => 'Meyer',
            'phone' => '+49 40 1234567',
            'street' => 'Am Hafen 5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
            'website' => 'https://bootsschule-mueller.de',
        ]);

        $response->assertRedirect();

        $branding = $tenant->branding->fresh();
        $this->assertSame('Anna', $branding->contact_first_name);
        $this->assertSame('Meyer', $branding->contact_last_name);
        $this->assertSame('+49 40 1234567', $branding->phone);
        $this->assertSame('Am Hafen 5', $branding->street);
        $this->assertSame('20095', $branding->postal_code);
        $this->assertSame('Hamburg', $branding->city);
        $this->assertSame('Deutschland', $branding->country);
        $this->assertSame('https://bootsschule-mueller.de', $branding->website);
    }

    public function test_country_must_be_one_of_the_supported_options(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'country' => 'Elbonien',
        ]);

        $response->assertSessionHasErrors('country');
    }

    public function test_changing_the_support_email_requires_verification_again(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        Mail::fake();

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'support_email' => 'kontakt@bootsschule-mueller.de',
        ]);

        $response->assertRedirect();

        $branding = $tenant->branding->fresh();
        $this->assertSame('kontakt@bootsschule-mueller.de', $branding->support_email);
        $this->assertNull($branding->support_email_verified_at);
        $this->assertFalse($branding->hasVerifiedSupportEmail());

        Mail::assertSent(VerifySupportEmail::class);
    }

    public function test_support_email_can_be_verified_via_the_signed_link(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'support_email' => 'kontakt@bootsschule-mueller.de',
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'admin.branding.support-email.verify',
            now()->addMinutes(60),
            ['tenant' => $tenant->id, 'hash' => sha1('kontakt@bootsschule-mueller.de')]
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get($verificationUrl);

        $response->assertRedirect(route('admin.dashboard', ['tab' => 'branding']));
        $this->assertNotNull($tenant->branding->fresh()->support_email_verified_at);
    }

    public function test_a_school_admin_can_enable_whatsapp_support_with_a_valid_phone_number(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'whatsapp_enabled' => '1',
            'whatsapp_phone' => '491701234567',
            'whatsapp_greeting' => 'Hallo {name}, ich habe eine Frage zu {kurs}.',
        ]);

        $response->assertRedirect();

        $branding = $tenant->branding->fresh();
        $this->assertTrue($branding->whatsapp_enabled);
        $this->assertSame('491701234567', $branding->whatsapp_phone);
        $this->assertSame('Hallo {name}, ich habe eine Frage zu {kurs}.', $branding->whatsapp_greeting);
    }

    public function test_whatsapp_phone_is_required_when_whatsapp_support_is_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'whatsapp_enabled' => '1',
        ]);

        $response->assertSessionHasErrors('whatsapp_phone');
        $this->assertFalse($tenant->branding->fresh()->whatsapp_enabled);
    }

    public function test_whatsapp_phone_must_be_in_international_format_without_spaces_or_plus(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'whatsapp_enabled' => '1',
            'whatsapp_phone' => '+49 170 1234567',
        ]);

        $response->assertSessionHasErrors('whatsapp_phone');
        $this->assertFalse($tenant->branding->fresh()->whatsapp_enabled);
    }

    public function test_whatsapp_support_can_be_disabled_without_a_phone_number(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
        ]);

        $response->assertRedirect();
        $this->assertFalse($tenant->branding->fresh()->whatsapp_enabled);
    }

    public function test_support_email_is_not_verified_with_an_invalid_hash(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'support_email' => 'kontakt@bootsschule-mueller.de',
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'admin.branding.support-email.verify',
            now()->addMinutes(60),
            ['tenant' => $tenant->id, 'hash' => sha1('wrong@example.test')]
        );

        $this->actingAsInTenant($admin, $tenant)->get($verificationUrl)->assertForbidden();
        $this->assertNull($tenant->branding->fresh()->support_email_verified_at);
    }
}
