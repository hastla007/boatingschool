<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Support\LegalPages;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Impressum und Datenschutzerklärung je Bootsschule aus einer zentralen
 * Vorlage (Superadmin) mit den Stammdaten der Schule aus dem Branding.
 */
class LegalPagesTest extends TestCase
{
    use InteractsWithTenants;

    private array $settingsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        // platform_setting ist eine geteilte Singleton-Zeile: nach dem Test zurücksetzen.
        $this->settingsBefore = $this->onAdmin(fn () => PlatformSetting::current()->only(['imprint_template', 'privacy_template', 'operator_info', 'maintenance_mode']));
    }

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => PlatformSetting::current()->update($this->settingsBefore));
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function schoolWithData(array $data = []): Tenant
    {
        $tenant = $this->createTestTenant('Hafen Bootsschule');
        $this->onAdmin(fn () => TenantBranding::query()->findOrFail($tenant->id)->update($data + [
            'legal_name' => 'Hafen Bootsschule GmbH',
            'street' => 'Kai 1',
            'postal_code' => '24103',
            'city' => 'Kiel',
            'country' => 'Deutschland',
            'contact_first_name' => 'Jana',
            'contact_last_name' => 'Kapitän',
            'phone' => '0431 123456',
            'support_email' => 'info@hafen-bootsschule.de',
        ]));

        return $tenant;
    }

    private function guest(Tenant $tenant): static
    {
        return $this->withSession(['dev_tenant_slug' => $tenant->slug]);
    }

    public function test_each_school_gets_an_imprint_with_its_own_data(): void
    {
        // Beide Schulen vor den Anfragen anlegen (eine Anfrage setzt den
        // Mandanten-Kontext der Testverbindung).
        $tenant = $this->schoolWithData(['representative' => 'Jana Kapitän (Geschäftsführerin)']);
        $other = $this->schoolWithData(['legal_name' => 'Elbe Segelschule', 'register_entry' => 'Amtsgericht Hamburg, HRB 999', 'vat_id' => 'DE123456789']);
        $this->onAdmin(fn () => PlatformSetting::current()->update(['imprint_template' => null, 'operator_info' => "Plattform GmbH\nWeg 2, 20095 Hamburg"]));

        $page = $this->guest($tenant)->get('/impressum')->assertOk();
        $page->assertSee('Hafen Bootsschule GmbH');
        $page->assertSee('Kai 1');
        $page->assertSee('24103 Kiel');
        $page->assertSee('Jana Kapitän (Geschäftsführerin)');
        $page->assertSee('info@hafen-bootsschule.de');
        $page->assertSee('Plattform GmbH');
        // Ohne Registereintrag und USt-IdNr. entfallen diese Zeilen.
        $page->assertDontSee('Registereintrag:');
        $page->assertDontSee('Umsatzsteuer-Identifikationsnummer');
        // … und mit ihnen die leere Überschrift.
        $page->assertDontSee('Register und Umsatzsteuer');

        $page = $this->guest($other)->get('/impressum')->assertOk();
        $page->assertSee('Elbe Segelschule');
        $page->assertDontSee('Hafen Bootsschule GmbH');
        $page->assertSee('Amtsgericht Hamburg, HRB 999');
        $page->assertSee('DE123456789');
    }

    public function test_the_privacy_policy_names_the_school_as_controller_and_the_operator(): void
    {
        $tenant = $this->schoolWithData();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['privacy_template' => null, 'operator_info' => 'Plattform GmbH']));

        $page = $this->guest($tenant)->get('/datenschutz')->assertOk();
        $page->assertSee('Datenschutzerklärung');
        $page->assertSee('Hafen Bootsschule GmbH');
        $page->assertSee('Plattform GmbH');
        $page->assertSee('Spracherkennung');
    }

    public function test_the_superadmin_template_is_used_and_html_is_escaped(): void
    {
        $tenant = $this->schoolWithData(['legal_name' => '<script>alert(1)</script> Schule']);
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->patch('/superadmin/settings', [
            'site_name' => PlatformSetting::siteName(),
            'imprint_template' => "## Wer wir sind\n\n{schule}, <b>fett</b>\nRegister: {register}",
            'privacy_template' => LegalPages::DEFAULT_DATENSCHUTZ,
            'operator_info' => 'Plattform GmbH',
        ])->assertRedirect();

        $settings = $this->onAdmin(fn () => PlatformSetting::current()->fresh());
        $this->assertStringContainsString('{schule}', $settings->imprint_template);
        // Der unveränderte Entwurf wird nicht gespeichert (spätere Verbesserungen greifen).
        $this->assertNull($settings->privacy_template);

        $page = $this->guest($tenant)->get('/impressum')->assertOk();
        $page->assertSee('Wer wir sind');
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertDontSee('<b>fett</b>', false);
        $page->assertDontSee('Register:');
    }

    public function test_links_appear_in_footer_login_and_registration_and_pages_work_during_maintenance(): void
    {
        $tenant = $this->schoolWithData();

        $this->guest($tenant)->get('/login')->assertOk()->assertSee('/impressum', false)->assertSee('/datenschutz', false);
        $this->guest($tenant)->get('/register')->assertOk()->assertSee('Informationen zur Verarbeitung deiner Daten', false)->assertSee('/datenschutz', false);

        $learner = $this->createTenantUser($tenant, 'learner');
        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()->assertSee('/impressum', false);

        $this->onAdmin(fn () => PlatformSetting::current()->update(['maintenance_mode' => true]));
        $this->guest($tenant)->get('/impressum')->assertOk();
        $this->guest($tenant)->get('/datenschutz')->assertOk();
    }

    public function test_the_school_admin_sees_missing_imprint_data_and_can_add_it(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=branding')->assertOk()
            ->assertSee('Für ein vollständiges Impressum fehlt noch', false);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'representative' => 'Max Muster',
            'register_entry' => 'Amtsgericht Kiel, HRB 1',
            'vat_id' => 'DE999999999',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $branding = $this->onAdmin(fn () => TenantBranding::query()->findOrFail($tenant->id));
        $this->assertSame('Max Muster', $branding->representative);
        $this->assertSame('Amtsgericht Kiel, HRB 1', $branding->register_entry);
        $this->assertSame('DE999999999', $branding->vat_id);
    }

    private function privacyPage(Tenant $tenant): string
    {
        return $this->guest($tenant)->get('/datenschutz')->assertOk()->getContent();
    }

    public function test_optional_service_sections_are_omitted_when_not_configured_and_sections_stay_numbered(): void
    {
        config(['privacy.video_provider.name' => null, 'sentry.dsn' => null, 'commerce.keys' => [], 'commerce.shop_url' => null]);
        $tenant = $this->schoolWithData();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['privacy_template' => null, 'operator_info' => 'Plattform GmbH']));

        $html = $this->privacyPage($tenant);

        $this->assertStringNotContainsString('Lernvideos', $html);
        $this->assertStringNotContainsString('Fehleranalyse', $html);
        $this->assertStringNotContainsString('Code-Shop', $html);
        // Immer: Datensicherung (14 Tage), Spracherkennung (freiwillig) und Speicherdauer je Datenart.
        $this->assertStringContainsString('Datensicherung', $html);
        $this->assertStringContainsString('nach 14 Tagen automatisch gelöscht', $html);
        $this->assertStringContainsString('Das ist freiwillig', $html);
        $this->assertStringContainsString('Server-Protokolle:</strong> 14 Tage', $html);
        $this->assertStringNotContainsString('Fehlerberichte:', $html);

        // Lückenlose Nummerierung trotz entfallener Abschnitte.
        preg_match_all('/<h2>(\d+)\./', $html, $numbers);
        $this->assertSame(range(1, count($numbers[1])), array_map('intval', $numbers[1]));
    }

    public function test_the_video_provider_section_appears_when_configured(): void
    {
        $tenant = $this->schoolWithData();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['privacy_template' => null]));

        config(['privacy.video_provider.name' => 'Videohost AG', 'privacy.video_provider.country' => 'Deutschland', 'privacy.video_provider.privacy_url' => 'https://videohost.example/datenschutz']);
        $html = $this->privacyPage($tenant);
        $this->assertStringContainsString('Lernvideos', $html);
        $this->assertStringContainsString('Videohost AG (Deutschland)', $html);
        $this->assertStringContainsString('https://videohost.example/datenschutz', $html);
        $this->assertStringNotContainsString('außerhalb der EU', $html);

        config(['privacy.video_provider.country' => 'USA']);
        $this->assertStringContainsString('außerhalb der EU bzw. des EWR', $this->privacyPage($tenant));
    }

    public function test_the_error_tracking_section_appears_only_with_a_sentry_dsn(): void
    {
        $tenant = $this->schoolWithData();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['privacy_template' => null]));

        config(['sentry.dsn' => 'https://public@o1.ingest.de.sentry.io/1']);
        $html = $this->privacyPage($tenant);
        $this->assertStringContainsString('Fehleranalyse', $html);
        $this->assertStringContainsString('in der EU (Frankfurt am Main)', $html);
        $this->assertStringContainsString('Fehlerberichte:</strong> 90 Tage', $html);

        config(['sentry.dsn' => 'https://public@o1.ingest.sentry.io/1']);
        $this->assertStringContainsString('Übermittlung in die USA', $this->privacyPage($tenant));

        config(['sentry.dsn' => 'https://public@sentry.betreiber.example/1']);
        $html = $this->privacyPage($tenant);
        $this->assertStringContainsString('in unserem Auftrag betriebene Sentry-Instanz', $html);
        $this->assertStringNotContainsString('USA', $html);
    }

    public function test_backup_shop_and_inactivity_retention_follow_the_configuration(): void
    {
        $tenant = $this->schoolWithData();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['privacy_template' => null]));
        config([
            'backup.keep_days' => 7, 'backup.disk' => 's3', 'privacy.backups.provider' => 'Speicher GmbH, Deutschland', 'privacy.backups.encrypted' => true,
            'commerce.keys' => ['shop' => 'geheim'],
            'data_retention.inactive_learners.months' => 24,
        ]);

        $html = $this->privacyPage($tenant);

        $this->assertStringContainsString('nach 7 Tagen automatisch gelöscht', $html);
        $this->assertStringContainsString('Speicheranbieter (Speicher GmbH, Deutschland)', $html);
        $this->assertStringContainsString('verschlüsselt', $html);
        $this->assertStringContainsString('Code-Shop für Bootsschulen', $html);
        $this->assertStringContainsString('nur Inhaber und Mitarbeitende der Bootsschule, nicht Lernende', $html);
        $this->assertStringContainsString('24 Monate lang nicht an', $html);
    }

    public function test_numbering_is_kept_for_custom_templates_without_numbers(): void
    {
        $markdown = "## Wer wir sind\n\nText\n\n## 2. Kontakt\n\nMail";

        $this->assertSame($markdown, LegalPages::renumberSections($markdown));
        $this->assertSame("## 1. A\n\n## 2. B", LegalPages::renumberSections("## 1. A\n\n## 5. B"));
    }
}
