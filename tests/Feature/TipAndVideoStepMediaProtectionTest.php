<?php

namespace Tests\Feature;

use App\Console\Commands\BackupApplication;
use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tip;
use App\Models\TipCategory;
use App\Models\User;
use App\Models\VideoLessonStep;
use App\Support\ProductionReadiness;
use App\Support\ProtectedMedia;
use App\Support\TipPdf;
use App\Support\VideoStepImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\MobileApiTestCase;

/**
 * Tipp-PDFs und Bilder der Videoschritte liegen privat und sind nur über
 * signierte, an Person und Schule gebundene Adressen abrufbar (TipPdf,
 * VideoStepImage, ProtectedMediaController, Migration 000820).
 */
class TipAndVideoStepMediaProtectionTest extends MobileApiTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    /** @var list<string> */
    private array $categoryIds = [];

    /** @var list<string> */
    private array $assetIds = [];

    /** @var array<string, ?string> ursprüngliche image_path je Schritt */
    private array $steps = [];

    /** @var list<array{0: string, 1: string}> [Disk, Pfad] */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            DB::table('tenant_branding')->whereIn('logo_asset_id', $this->assetIds)->update(['logo_asset_id' => null]);
            $assetIds = array_merge($this->assetIds, Tip::whereIn('category_id', $this->categoryIds)->whereNotNull('pdf_asset_id')->pluck('pdf_asset_id')->all());
            foreach (MediaAsset::whereIn('id', $assetIds)->get() as $asset) {
                ProtectedMedia::delete($asset->getRawOriginal('storage_path'));
            }
            Tip::whereIn('category_id', $this->categoryIds)->delete();
            TipCategory::whereIn('id', $this->categoryIds)->delete();
            MediaAsset::whereIn('id', $assetIds)->delete();
            foreach ($this->steps as $id => $path) {
                VideoLessonStep::whereKey($id)->update(['image_path' => $path]);
            }
        });
        foreach ($this->files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
        parent::tearDown();
    }

    public function test_superadmin_upload_lands_on_the_private_disk(): void
    {
        $category = $this->category();

        $this->actingAs($this->createSuperAdmin())->post('/superadmin/tips', [
            'category_id' => $category->id,
            'title' => 'Knoten üben',
            'body' => 'Mit Anhang.',
            'pdf' => UploadedFile::fake()->createWithContent('anleitung.pdf', self::PDF),
        ])->assertRedirect();

        $tip = $this->onAdmin(fn () => Tip::with('pdfAsset')->where('category_id', $category->id)->firstOrFail());
        $path = $tip->pdfAsset->getRawOriginal('storage_path');
        $this->assertStringStartsWith(TipPdf::DIRECTORY.'/', $path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertSame('licensed', $tip->pdfAsset->rights_status);

        // Die Vorschau im Editor zeigt eine funktionierende, signierte Adresse.
        $edit = $this->actingAs($this->createSuperAdmin())->get("/superadmin/tips/{$tip->id}/edit")->assertOk();
        $this->assertStringNotContainsString('/storage/'.TipPdf::DIRECTORY, $edit->getContent());
        $this->get($this->link($edit->getContent(), '/media/tip-pdfs/'.$tip->id))->assertOk();
    }

    public function test_tips_page_shows_a_working_signed_pdf_link_and_the_old_public_address_is_gone(): void
    {
        [$tenant, $learner] = $this->learner();
        $tip = $this->tipWithPdf('Knoten & Leinen');

        $page = $this->actingAsInTenant($learner, $tenant)->get('/tipps-tricks')->assertOk();
        $this->assertStringNotContainsString('/storage/'.TipPdf::DIRECTORY, $page->getContent());
        $signed = $this->link($page->getContent(), '/media/tip-pdfs/'.$tip->id);

        $pdf = $this->get($signed)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $pdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''Knoten%20%26%20Leinen.pdf", $pdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $pdf->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $pdf->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $pdf->headers->get('X-Content-Type-Options'));
        // Inline im Browser: ohne sandbox (sonst zeigt Chrome das PDF nicht),
        // PDF-Betrachter erlaubt, aber weiterhin kein Skript und kein
        // Einbetten auf fremden Seiten; die Medienroute läuft ohne die
        // globalen Sicherheits-Header (kein X-Frame-Options: DENY).
        $csp = $pdf->headers->get('Content-Security-Policy');
        $this->assertSame(ProtectedMedia::PDF_CSP, $csp);
        $this->assertStringNotContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
        $this->assertFalse($pdf->headers->has('X-Frame-Options'));

        // Liegt unter dem Tipp keine echte PDF-Datei, gibt es einen Anhang mit Sandbox.
        $fake = $this->tipWithPdf('Kein PDF');
        Storage::disk('local')->put($fake->pdfAsset->getRawOriginal('storage_path'), '<html><script>alert(1)</script></html>');
        $attachment = $this->get(TipPdf::url($fake, $learner, $tenant))->assertOk();
        $this->assertSame('application/octet-stream', $attachment->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $attachment->headers->get('Content-Disposition'));
        $this->assertSame(ProtectedMedia::SANDBOX_CSP, $attachment->headers->get('Content-Security-Policy'));

        // Ohne Signatur bzw. mit verändertem Parameter: abgelehnt.
        $this->get(strtok($signed, '?'))->assertForbidden();
        $this->get(preg_replace('/u=[0-9a-f-]+/', 'u='.Str::uuid(), $signed))->assertForbidden();

        // Die alte öffentliche Adresse gibt es nicht mehr.
        $path = $tip->pdfAsset->getRawOriginal('storage_path');
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->get('/storage/'.$path)->assertNotFound();

        // Abgelaufen.
        $this->travel(TipPdf::URL_MINUTES + 1)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();
    }

    public function test_tip_pdfs_need_an_active_membership_in_a_running_school(): void
    {
        [$tenant, $learner] = $this->learner();
        $tip = $this->tipWithPdf('Wetter');

        // Tipps gelten plattformweit: Mitglieder jeder Schule (ohne Kurszugang).
        $other = $this->createTestTenant();
        $stranger = $this->createTenantUser($other, 'learner');
        $this->get(TipPdf::url($tip, $stranger, $other))->assertOk();

        // Mit einer Schule signiert, in der die Person kein Mitglied ist, oder ohne Schule.
        $this->get(TipPdf::url($tip, $learner, $other))->assertNotFound();
        $this->get(TipPdf::url($tip, $learner, null))->assertNotFound();

        // Gesperrte Mitgliedschaft wirkt sofort, auch mit noch gültiger Adresse.
        $url = TipPdf::url($tip, $learner, $tenant);
        $this->get($url)->assertOk();
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $learner->id)->update(['status' => 'suspended']));
        $this->get($url)->assertNotFound();

        // Gesperrte Schule.
        $strangerUrl = TipPdf::url($tip, $stranger, $other);
        $this->onAdmin(fn () => $other->update(['status' => 'suspended']));
        $this->get($strangerUrl)->assertNotFound();

        // Deaktivierter Tipp: nur noch für den Superadmin (auch ohne Schule).
        [$tenant2, $learner2] = $this->learner();
        $this->onAdmin(fn () => $tip->update(['active' => false]));
        $this->get(TipPdf::url($tip, $learner2, $tenant2))->assertNotFound();
        $this->get(TipPdf::url($tip, $this->createSuperAdmin(), null))->assertOk();
    }

    public function test_tips_api_returns_a_signed_pdf_url_and_rewrites_old_links(): void
    {
        [$tenant, $learner] = $this->learner();
        $tip = $this->tipWithPdf('Palstek');
        $path = $tip->pdfAsset->getRawOriginal('storage_path');
        $this->onAdmin(fn () => $tip->update(['body' => '<p><a href="/storage/'.$path.'">PDF</a></p>']));

        $response = $this->api('GET', '/tips', $learner, $tenant)->assertOk();
        $data = collect($response->json('data'))->flatMap(fn ($group) => $group['tips'])->firstWhere('id', $tip->id);
        $url = $data['pdf']['url'];
        $this->assertStringContainsString('/media/tip-pdfs/'.$tip->id.'?', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertSame('application/pdf', $data['pdf']['mime_type']);
        $this->assertStringNotContainsString('/storage/', $data['html']);
        $this->assertSame($url, $data['links'][0]['url']);
        $this->get($url)->assertOk();

        // Tipps ohne PDF: null.
        $plain = $this->onAdmin(fn () => Tip::create(['category_id' => $tip->category_id, 'title' => 'Ohne', 'body' => 'x', 'active' => true]));
        $data = collect($this->api('GET', '/tips', $learner, $tenant)->json('data'))->flatMap(fn ($group) => $group['tips'])->firstWhere('id', $plain->id);
        $this->assertNull($data['pdf']);
    }

    public function test_video_step_images_are_signed_in_web_and_api_and_follow_the_course(): void
    {
        [$tenant, $learner] = $this->learner();
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $step = $this->stepWithImage($course);
        $lesson = $step->lesson;

        $page = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/video/{$lesson->id}?kapitel={$lesson->video_module_id}")->assertOk();
        $this->assertStringNotContainsString('/storage/'.$step->image_path, $page->getContent());
        $signed = $this->link($page->getContent(), '/media/video-steps/'.$step->id);
        $image = $this->get($signed)->assertOk();
        $this->assertSame('image/png', $image->headers->get('Content-Type'));
        $this->assertSame('inline', $image->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $this->assertSame(ProtectedMedia::SANDBOX_CSP, $image->headers->get('Content-Security-Policy'));
        $this->get('/storage/'.$step->image_path)->assertNotFound();

        // API: das Feld url bleibt, ist aber signiert.
        $api = $this->api('GET', "/videos/{$lesson->id}", $learner, $tenant)->assertOk();
        $media = collect($api->json('data.steps'))->firstWhere('id', $step->id)['media'][0];
        $this->assertSame('image', $media['kind']);
        $this->assertStringContainsString('/media/video-steps/'.$step->id.'?', $media['url']);
        $this->assertStringContainsString('signature=', $media['url']);
        $this->get($media['url'])->assertOk();

        $this->travel(VideoStepImage::URL_MINUTES + 1)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();
    }

    public function test_video_step_images_need_access_to_the_course_of_the_video(): void
    {
        [$tenant, $learner] = $this->learner();
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $step = $this->stepWithImage($course);

        // Kurs ohne dieses Video (FKN).
        $funker = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $funker, $this->existingCourse('FKN'));
        $this->get(VideoStepImage::url($step, $funker, $tenant))->assertNotFound();

        // Andere Schule ohne Zugang; eigene Person mit fremder Schule signiert.
        $other = $this->createTestTenant();
        $this->get(VideoStepImage::url($step, $this->createTenantUser($other, 'learner'), $other))->assertNotFound();
        $this->get(VideoStepImage::url($step, $learner, $other))->assertNotFound();

        // Mit Zugang erlaubt, nach Sperre der Mitgliedschaft nicht mehr.
        $url = VideoStepImage::url($step, $learner, $tenant);
        $this->get($url)->assertOk();
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $learner->id)->update(['status' => 'suspended']));
        $this->get($url)->assertNotFound();

        // Superadmin immer, auch ohne Schule.
        $this->get(VideoStepImage::url($step, $this->createSuperAdmin(), null))->assertOk();
    }

    public function test_migration_moves_tip_pdfs_and_step_images_idempotently_and_back(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $pdf = TipPdf::DIRECTORY.'/'.Str::uuid().'.pdf';
        $orphan = TipPdf::DIRECTORY.'/'.Str::uuid().'.pdf';
        $logo = 'branding-logos/'.Str::uuid().'.png';
        $stepPath = 'video-schritte/'.Str::uuid().'.png';
        Storage::disk('public')->put($pdf, self::PDF);
        Storage::disk('public')->put($orphan, self::PDF);
        Storage::disk('public')->put($logo, 'logo');
        Storage::disk('public')->put($stepPath, 'bild');

        $tip = $this->tipWithPdf('Migration', $pdf, onDisk: false);
        // Ersetzter Upload ohne Tipp (war ebenso öffentlich).
        $this->asset($orphan, 'tip-pdf-'.Str::random(12));
        // Ein Logo als Tipp-PDF bleibt sicherheitshalber öffentlich.
        $logoAsset = $this->asset($logo, 'TEST-LOGO-'.Str::random(8), 'public');
        $school = $this->createTestTenant();
        $this->onAdmin(fn () => DB::table('tenant_branding')->updateOrInsert(['tenant_id' => $school->id], ['logo_asset_id' => $logoAsset->id]));
        $this->stepWithImage($this->existingCourse('SBF-SEE'), $stepPath, onDisk: false);

        $this->assertContains($pdf, TipPdf::filesOnPublicDisk()->all());
        $this->assertContains($stepPath, VideoStepImage::filesOnPublicDisk()->all());

        $this->runMigration('up');
        foreach ([$pdf, $orphan, $stepPath] as $path) {
            Storage::disk('local')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }
        Storage::disk('public')->assertExists($logo);
        Storage::disk('local')->assertMissing($logo);

        // Zweiter Lauf: nichts kaputt, nichts doppelt.
        $this->runMigration('up');
        $this->assertSame(self::PDF, Storage::disk('local')->get($pdf));
        $this->assertSame('bild', Storage::disk('local')->get($stepPath));
        Storage::disk('public')->assertMissing($pdf);
        $this->assertTrue(TipPdf::filesOnPublicDisk()->isEmpty());

        $this->runMigration('down');
        foreach ([$pdf, $orphan, $stepPath] as $path) {
            Storage::disk('public')->assertExists($path);
            Storage::disk('local')->assertMissing($path);
        }
        Storage::disk('public')->assertExists($logo);
        $this->assertNotNull($tip);
    }

    public function test_go_live_check_warns_about_tip_pdfs_and_step_images_on_the_public_disk(): void
    {
        Storage::fake('public');
        $label = 'Tipp-PDFs und Videoschritt-Bilder nicht öffentlich abrufbar';
        $tip = $this->tipWithPdf('Go-live');
        $step = $this->stepWithImage($this->existingCourse('SBF-SEE'));

        $this->assertSame('ok', collect(ProductionReadiness::checks())->firstWhere('label', $label)['status']);

        Storage::disk('public')->put($tip->pdfAsset->getRawOriginal('storage_path'), self::PDF);
        $check = collect(ProductionReadiness::checks())->firstWhere('label', $label);
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString($tip->pdfAsset->getRawOriginal('storage_path'), $check['detail']);

        Storage::disk('public')->delete($tip->pdfAsset->getRawOriginal('storage_path'));
        Storage::disk('public')->put($step->image_path, 'bild');
        $this->assertSame('warn', collect(ProductionReadiness::checks())->firstWhere('label', $label)['status']);
    }

    public function test_backup_includes_the_new_private_directories_and_loose_step_images(): void
    {
        Storage::fake('local');
        $loose = 'schritt-'.Str::uuid().'.png';
        Storage::disk('local')->put($loose, 'bild');
        Storage::disk('local')->put(TipPdf::DIRECTORY.'/a.pdf', self::PDF);
        $this->stepWithImage($this->existingCourse('SBF-SEE'), $loose, onDisk: false);

        $this->assertContains(TipPdf::DIRECTORY, BackupApplication::PRIVATE_MEDIA_DIRECTORIES);
        $this->assertContains(VideoStepImage::DIRECTORY, BackupApplication::PRIVATE_MEDIA_DIRECTORIES);
        $entries = BackupApplication::privateEntries();
        $this->assertContains(TipPdf::DIRECTORY, $entries);
        $this->assertContains(VideoStepImage::DIRECTORY, $entries);
        $this->assertContains($loose, $entries);
    }

    public function test_routes_are_signed_and_throttled(): void
    {
        foreach (['tip-pdf.file', 'video-step.image'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('signed', $middleware, $name);
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
            // Ohne Session/Mandant (wie QuestionMedia): kein web-Stack.
            $this->assertNotContains('web', $middleware, $name);
        }
    }

    public function test_paths_outside_the_disk_are_never_served(): void
    {
        $this->assertNull(ProtectedMedia::locate('../../.env'));
        $this->assertNull(ProtectedMedia::locate(TipPdf::DIRECTORY.'/../../../.env'));
        $this->assertNull(ProtectedMedia::relativePath('https://example.org/a.pdf'));

        $step = $this->stepWithImage($this->existingCourse('SBF-SEE'), '../../.env', onDisk: false);
        $this->get(VideoStepImage::url($step, $this->createSuperAdmin(), null))->assertNotFound();
    }

    /** @return array{0: Tenant, 1: User} */
    private function learner(): array
    {
        $tenant = $this->createTestTenant();

        return [$tenant, $this->createTenantUser($tenant, 'learner')];
    }

    private function category(): TipCategory
    {
        $category = $this->onAdmin(fn () => TipCategory::create(['name' => 'Test-PDF '.Str::random(8), 'sort_order' => 999]));
        $this->categoryIds[] = $category->id;

        return $category;
    }

    private function tipWithPdf(string $title, ?string $path = null, bool $onDisk = true): Tip
    {
        $path ??= TipPdf::DIRECTORY.'/'.Str::uuid().'.pdf';
        if ($onDisk) {
            Storage::disk('local')->put($path, self::PDF);
        }
        $asset = $this->asset($path, 'tip-pdf-'.Str::random(12));
        $category = $this->category();

        return $this->onAdmin(fn () => Tip::create([
            'category_id' => $category->id, 'title' => $title, 'body' => 'Siehe PDF.', 'pdf_asset_id' => $asset->id, 'active' => true,
        ])->load('pdfAsset'));
    }

    private function asset(string $path, string $key, string $rights = 'licensed'): MediaAsset
    {
        $asset = $this->onAdmin(fn () => MediaAsset::create([
            'asset_key' => $key, 'media_type' => 'document', 'storage_path' => $path,
            'mime_type' => 'application/pdf', 'rights_status' => $rights, 'status' => 'published',
        ]));
        $this->assetIds[] = $asset->id;

        return $asset;
    }

    private function stepWithImage(CourseDefinition $course, ?string $path = null, bool $onDisk = true): VideoLessonStep
    {
        $step = $this->onAdmin(fn () => VideoLessonStep::whereHas('lesson.module', fn ($q) => $q->where('course_id', $course->id))
            ->whereNotIn('id', array_keys($this->steps))->orderBy('id')->firstOrFail());
        $this->steps[$step->id] = $step->image_path;
        $path ??= VideoStepImage::DIRECTORY.'/test/'.Str::uuid().'.png';
        if ($onDisk) {
            Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            $this->files[] = ['local', $path];
        }
        $this->onAdmin(fn () => $step->update(['image_path' => $path]));

        return $this->onAdmin(fn () => $step->fresh('lesson'));
    }

    private function link(string $html, string $path): string
    {
        preg_match('#(?:href|src)="([^"]*'.preg_quote($path, '#').'\?[^"]+)"#', $html, $m);
        $this->assertNotEmpty($m, "signierte Adresse {$path} auf der Seite");

        return html_entity_decode($m[1]);
    }

    private function runMigration(string $direction): void
    {
        $migration = require database_path('migrations/2024_02_01_000820_move_tip_pdfs_and_video_step_images_to_private_disk.php');
        $this->onAdmin(fn () => $migration->{$direction}());
    }
}
