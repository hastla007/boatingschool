<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview H4: ein als Logo hochgeladenes SVG könnte <script>
 * enthalten und wird vom Browser als HTML/XML statt als reines Bild
 * interpretiert -- über die ausgelieferte Logo-URL wäre das gespeichertes
 * XSS im Ursprung der Plattform. BrandingController lässt deshalb nur noch
 * png/jpg/jpeg/webp zu und kodiert jeden Upload über GD zu einem frischen
 * PNG neu, statt die hochgeladene Datei unverändert abzulegen.
 */
class BrandingLogoUploadTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_valid_png_logo_is_accepted_and_re_encoded(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $asset = MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->first();
        $this->assertNotNull($asset);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertStringEndsWith('.png', $asset->storage_path);
    }

    public function test_an_svg_logo_upload_is_rejected(): void
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
            'logo' => $svg,
        ]);

        $response->assertSessionHasErrors('logo');
        $this->assertNull(MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->first());
        Storage::disk('public')->assertDirectoryEmpty('branding-logos');
    }

    public function test_a_file_with_a_disguised_svg_extension_is_rejected(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        // SVG-Inhalt, aber mit .png-Dateiendung -- die "mimes"-Regel prüft
        // den tatsächlichen Inhalt, nicht nur die angegebene Endung.
        $disguised = UploadedFile::fake()->createWithContent(
            'logo.png',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $response = $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => $disguised,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    /**
     * DSGVO-Review D2: das bisherige Logo (Datei + media_asset-Zeile) wurde
     * beim Ersetzen nie entfernt und sammelte sich unbegrenzt an.
     */
    public function test_replacing_the_logo_deletes_the_old_file_and_media_asset_row(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
        ]);

        $oldAsset = MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->sole();
        $oldPath = str_replace(Storage::disk('public')->url(''), '', $oldAsset->storage_path);
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => UploadedFile::fake()->image('logo-neu.png', 120, 80),
        ]);

        $this->assertNull(MediaAsset::find($oldAsset->id));
        Storage::disk('public')->assertMissing($oldPath);

        $newAsset = MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->sole();
        $this->assertNotSame($oldAsset->id, $newAsset->id);
    }
}
