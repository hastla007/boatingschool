<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U4: favicon_asset_id/primary_color/secondary_color
 * existierten in tenant_branding bereits, hatten aber weder ein Formularfeld
 * noch eine Verarbeitung (Favicon) bzw. wurden nie als CSS-Variable
 * ausgegeben (Farben) -- das Favicon blieb für jede Bootsschule leer, ein
 * Farbwert ohne Wirkung. Zusätzlich fehlte das Logo in Navigation und
 * Login-Seite, obwohl es für die Kursseite längst funktionierte.
 */
class BrandingFaviconAndColorsTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdMediaAssetIds as $id) {
                MediaAsset::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_a_valid_png_favicon_is_accepted_and_re_encoded(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $asset = MediaAsset::where('asset_key', 'like', 'branding-favicon-'.$tenant->id.'-%')->first();
        $this->assertNotNull($asset);
        $this->createdMediaAssetIds[] = $asset->id;
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertStringEndsWith('.png', $asset->storage_path);
    }

    public function test_an_svg_favicon_upload_is_rejected(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $svg = UploadedFile::fake()->createWithContent(
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>'
        );

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'favicon' => $svg,
        ]);

        $response->assertSessionHasErrors('favicon');
        $this->assertNull(MediaAsset::where('asset_key', 'like', 'branding-favicon-'.$tenant->id.'-%')->first());
        Storage::disk('public')->assertDirectoryEmpty('branding-favicons');
    }

    public function test_replacing_the_favicon_deletes_the_old_file_and_media_asset_row(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ]);

        $oldAsset = MediaAsset::where('asset_key', 'like', 'branding-favicon-'.$tenant->id.'-%')->sole();
        $oldPath = str_replace(Storage::disk('public')->url(''), '', $oldAsset->storage_path);
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'favicon' => UploadedFile::fake()->image('favicon-neu.png', 32, 32),
        ]);

        $this->assertNull(MediaAsset::find($oldAsset->id));
        Storage::disk('public')->assertMissing($oldPath);

        $newAsset = MediaAsset::where('asset_key', 'like', 'branding-favicon-'.$tenant->id.'-%')->sole();
        $this->createdMediaAssetIds[] = $newAsset->id;
        $this->assertNotSame($oldAsset->id, $newAsset->id);
    }

    public function test_a_school_admin_can_configure_primary_and_secondary_colors(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'primary_color' => '#ab12cd',
            'secondary_color' => '#112233',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $tenant->branding->refresh();
        $this->assertSame('#ab12cd', $tenant->branding->primary_color);
        $this->assertSame('#112233', $tenant->branding->secondary_color);
    }

    public function test_an_invalid_color_format_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'primary_color' => 'javascript:alert(1)',
        ]);

        $response->assertSessionHasErrors('primary_color');
    }

    /**
     * Regressionstest fürs eigentliche Symptom: "Farben" hatte bisher keine
     * Wirkung, weil --brand-primary/--brand-secondary nirgends als
     * CSS-Variable definiert wurden -- jede Verwendung von
     * var(--brand-primary, #005FD7) im Markup griff dadurch immer nur auf
     * den hartkodierten Fallback zurück.
     */
    public function test_login_page_renders_the_tenant_primary_color_as_css_variable(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertSee('--brand-primary: #123456;', false);
    }

    public function test_login_page_shows_the_tenant_logo_instead_of_the_generic_icon(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
        ]);

        $asset = MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->sole();
        $this->createdMediaAssetIds[] = $asset->id;

        // actingAsInTenant() loggt den Owner für den Test-Client ein -- für
        // die Login-Seite muss hier wieder als Gast zugegriffen werden.
        $this->post('/logout');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertSee($asset->storage_path, false);
    }

    public function test_login_page_falls_back_to_the_platform_favicon_when_the_tenant_has_none(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertSee('favicon.svg', false);
    }

    public function test_login_page_uses_the_tenant_favicon_when_set(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ]);

        $asset = MediaAsset::where('asset_key', 'like', 'branding-favicon-'.$tenant->id.'-%')->sole();
        $this->createdMediaAssetIds[] = $asset->id;

        $this->post('/logout');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertSee($asset->storage_path, false);
    }
}
