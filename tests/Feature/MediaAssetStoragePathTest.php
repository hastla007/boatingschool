<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * O2: BrandingController/TipController speicherten in media_asset.
 * storage_path bislang die zum Upload-Zeitpunkt aufgelöste volle URL
 * (inkl. Host, in lokaler Entwicklung also "http://localhost:8000/...").
 * Zöge die Installation später auf eine andere Domain/einen CDN-Host um,
 * blieben alle vorher hochgeladenen Logos/PDFs auf den alten Host
 * verweisen. MediaAsset::storagePath() speichert jetzt nur noch den
 * relativen Pfad und löst die volle URL beim Lesen stets frisch aus der
 * aktuellen Konfiguration auf.
 */
class MediaAssetStoragePathTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        // Bug-Review Testisolation: media_asset hat keinen tenant_id/FK, der
        // beim Löschen des Tenants mitkaskadiert -- ohne dieses Aufräumen
        // blieben die hier angelegten Assets dauerhaft als Daten-Reste in
        // der Tabelle stehen.
        $this->onAdmin(function () {
            foreach ($this->createdMediaAssetIds as $id) {
                MediaAsset::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_a_newly_created_asset_stores_only_the_relative_path_in_the_database(): void
    {
        Storage::fake('public');

        $asset = MediaAsset::create([
            'asset_key' => 'test-asset-'.uniqid(),
            'media_type' => 'image',
            'storage_path' => 'branding-logos/example.png',
            'mime_type' => 'image/png',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
        $this->createdMediaAssetIds[] = $asset->id;

        $rawValue = $asset->getRawOriginal('storage_path');

        $this->assertSame('branding-logos/example.png', $rawValue);
        $this->assertStringStartsNotWith('http', $rawValue);
    }

    public function test_the_full_url_is_resolved_fresh_from_the_current_app_url_instead_of_being_frozen_at_write_time(): void
    {
        Storage::fake('public');

        $asset = MediaAsset::create([
            'asset_key' => 'test-asset-'.uniqid(),
            'media_type' => 'image',
            'storage_path' => 'branding-logos/example.png',
            'mime_type' => 'image/png',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
        $this->createdMediaAssetIds[] = $asset->id;

        config(['filesystems.disks.public.url' => 'http://localhost:8000/storage']);
        Storage::forgetDisk('public');
        $this->assertStringStartsWith('http://localhost:8000/storage', $asset->fresh()->storage_path);

        // Simuliert einen Domain-Umzug NACH dem Upload (neuer APP_URL beim
        // nächsten Boot -- filesystems.php leitet den Disk-Präfix davon
        // ab): ohne Codeänderung oder erneuten Upload muss die
        // ausgelieferte URL dem neuen Host folgen, statt weiterhin auf den
        // alten Host zu verweisen, mit dem ursprünglich hochgeladen wurde.
        config(['filesystems.disks.public.url' => 'https://app.example.test/storage']);
        Storage::forgetDisk('public');
        $this->assertStringStartsWith('https://app.example.test/storage', $asset->fresh()->storage_path);
    }

    public function test_setting_a_full_absolute_url_is_normalized_down_to_the_relative_path_before_saving(): void
    {
        Storage::fake('public');

        $path = 'branding-logos/legacy.png';
        $absoluteUrl = Storage::disk('public')->url($path);

        $asset = MediaAsset::create([
            'asset_key' => 'test-asset-'.uniqid(),
            'media_type' => 'image',
            // Simuliert einen Aufrufer, der (wie der alte Controller-Code)
            // weiterhin die volle URL übergibt -- der Mutator muss das
            // trotzdem auf den relativen Pfad zurückschneiden.
            'storage_path' => $absoluteUrl,
            'mime_type' => 'image/png',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
        $this->createdMediaAssetIds[] = $asset->id;

        $this->assertSame($path, $asset->getRawOriginal('storage_path'));
    }

    public function test_a_pre_existing_row_with_an_already_stored_absolute_url_from_an_unknown_host_still_renders(): void
    {
        Storage::fake('public');

        $asset = MediaAsset::create([
            'asset_key' => 'test-asset-'.uniqid(),
            'media_type' => 'image',
            'storage_path' => 'placeholder.png',
            'mime_type' => 'image/png',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
        $this->createdMediaAssetIds[] = $asset->id;

        // Altbestand aus der Zeit vor diesem Fix: direkt (unter Umgehung
        // des Mutators) eine volle URL eines Hosts hinterlegt, der nicht
        // mehr dem aktuellen Storage-Präfix entspricht.
        $asset->forceFill(['storage_path' => 'http://alter-host.example/storage/placeholder.png'])->saveQuietly();

        $this->assertSame(
            'http://alter-host.example/storage/placeholder.png',
            $asset->fresh()->storage_path
        );
    }

    public function test_branding_logo_upload_stores_only_the_relative_path(): void
    {
        Storage::fake('public');

        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/branding', [
            'exam_readiness_threshold_percent' => 50,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
        ]);

        $asset = MediaAsset::where('asset_key', 'like', 'branding-logo-'.$tenant->id.'-%')->first();
        $this->assertNotNull($asset);
        $this->createdMediaAssetIds[] = $asset->id;
        $this->assertStringStartsNotWith('http', $asset->getRawOriginal('storage_path'));
    }
}
