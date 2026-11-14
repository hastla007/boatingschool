<?php

namespace Tests\Feature\Api;

use App\Api\CourseAccess;
use App\Api\QuestionPresenter;
use App\Models\CourseAccessRule;
use App\Models\CourseDefinition;
use App\Models\CourseFile;
use App\Models\Entitlement;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Kursdateien und Fragenbilder der Mobile-API: Liste im Kursdetail,
 * geprüfter Download über GET /files/{id} (Funktion downloads, Probezugang,
 * Schul-Isolation, Pfadschutz) und der Import aus dem WP-Export.
 */
class CourseFilesApiTest extends MobileApiTestCase
{
    /** @var list<string> */
    private array $fileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CourseFile::DISK);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => CourseFile::whereIn('id', $this->fileIds)->delete());
        CourseAccess::bumpContentVersion();
        parent::tearDown();
    }

    private function courseFile(CourseDefinition $course, string $category, array $attributes = []): CourseFile
    {
        $path = $attributes['storage_path'] ?? CourseFile::DIRECTORY.'/test/'.Str::uuid().'.pdf';
        Storage::disk(CourseFile::DISK)->put($path, '%PDF-1.4 '.Str::random(40));
        $file = $this->onAdmin(fn () => CourseFile::create($attributes + [
            'course_id' => $course->id, 'category' => $category, 'title' => 'Datei '.Str::random(6),
            'storage_path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 49,
            'sort_order' => count($this->fileIds), 'published' => true,
        ]));
        $this->fileIds[] = $file->id;
        CourseAccess::bumpContentVersion();

        return $file;
    }

    private function partialEntitlement(Tenant $tenant, User $user, CourseDefinition $course, array $capabilities): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subMinute(),
            'capabilities' => $capabilities,
        ]));
    }

    private function rule(Tenant $tenant, CourseDefinition $course, string $feature, string $type, ?int $limit = null): void
    {
        $this->onAdmin(fn () => CourseAccessRule::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'feature_code' => $feature,
            'access_type' => $type, 'limit_value' => $limit,
        ]));
    }

    public function test_course_detail_lists_files_locked_without_access_and_download_is_refused(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $download = $this->courseFile($course, 'download');
        $section = $this->courseFile($course, 'section', ['section_key' => 'funkverkehr']);
        $chart = $this->courseFile($course, 'chart', ['section_key' => 'karte-1']);
        $token = $this->apiLogin($user);

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertContains('downloads', $detail->json('data.capabilities'));
        $this->assertSame(['available' => false, 'full' => false, 'limit' => null], $detail->json('data.features.downloads'));
        $this->assertSame($download->id, $detail->json('data.downloads.0.id'));
        $this->assertTrue($detail->json('data.downloads.0.locked'));
        $this->assertNull($detail->json('data.downloads.0.url'));
        $this->assertSame('downloads', $detail->json('data.downloads.0.required_capability'));
        $this->assertSame($section->id, $detail->json('data.section_files.funkverkehr.0.id'));
        $this->assertSame('karte-1', $detail->json('data.charts.0.code'));
        $this->assertSame($chart->id, $detail->json('data.charts.0.id'));

        foreach ([$download, $section, $chart] as $file) {
            $this->api('GET', "/files/{$file->id}", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        }
    }

    public function test_full_course_access_downloads_files_from_the_private_disk(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $file = $this->courseFile($course, 'download', ['title' => 'Übungsbogen Funk']);
        $token = $this->apiLogin($user);

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertFalse($detail->json('data.downloads.0.locked'));
        $this->assertSame(route('api.v1.files.download', ['fileId' => $file->id]), $detail->json('data.downloads.0.url'));

        $response = $this->api('GET', "/files/{$file->id}", $token, $tenant)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''%C3%9Cbungsbogen%20Funk.pdf", $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk(CourseFile::DISK)->path($file->storage_path), $response->baseResponse->getFile()->getPathname());

        // Fortsetzen großer Dateien per Range.
        $partial = $this->api('GET', "/files/{$file->id}", $token, $tenant, [], ['Range' => 'bytes=0-3']);
        $partial->assertStatus(206);
        $this->assertSame('bytes 0-3/'.Storage::disk(CourseFile::DISK)->size($file->storage_path), $partial->headers->get('Content-Range'));

        // Ohne Schule kein Download.
        $this->api('GET', "/files/{$file->id}", $token)->assertStatus(422);
    }

    public function test_html_and_unknown_types_are_only_sent_as_attachment(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $file = $this->courseFile($course, 'download', ['mime_type' => 'text/html', 'storage_path' => CourseFile::DIRECTORY.'/test/'.Str::uuid().'.html']);

        $response = $this->api('GET', "/files/{$file->id}", $user, $tenant)->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_downloads_capability_opens_downloads_but_not_files_that_need_full_access(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->partialEntitlement($tenant, $user, $course, ['downloads']);
        $download = $this->courseFile($course, 'download');
        $section = $this->courseFile($course, 'section', ['section_key' => 'a']);
        $examFile = $this->courseFile($course, 'section', ['section_key' => 'a', 'required_capability' => 'exam']);
        $token = $this->apiLogin($user);

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertTrue($detail->json('data.features.downloads.full'));
        $this->assertFalse($detail->json('data.downloads.0.locked'));
        $this->assertTrue($detail->json('data.section_files.a.0.locked'));
        $this->assertTrue($detail->json('data.section_files.a.1.locked'));

        $this->api('GET', "/files/{$download->id}", $token, $tenant)->assertOk();
        $this->api('GET', "/files/{$section->id}", $token, $tenant)->assertStatus(403);
        $this->api('GET', "/files/{$examFile->id}", $token, $tenant)->assertStatus(403);

        // Eine Prüfungs-Capability öffnet die daran gebundene Datei, ein Download-Recht nicht.
        $this->partialEntitlement($tenant, $user, $course, ['exam']);
        $this->api('GET', "/files/{$examFile->id}", $token, $tenant)->assertOk();
        $this->api('GET', "/files/{$section->id}", $token, $tenant)->assertStatus(403);
    }

    public function test_free_limited_rule_opens_only_the_first_downloads(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->rule($tenant, $course, 'downloads', 'free_limited', 1);
        $first = $this->courseFile($course, 'download');
        $second = $this->courseFile($course, 'download');
        $token = $this->apiLogin($user);

        $detail = $this->api('GET', "/courses/{$course->id}", $token, $tenant)->assertOk();
        $this->assertSame(['available' => true, 'full' => false, 'limit' => 1], $detail->json('data.features.downloads'));
        $this->assertSame([$first->id, $second->id], array_column($detail->json('data.downloads'), 'id'));
        $this->assertSame([false, true], array_column($detail->json('data.downloads'), 'locked'));

        $this->api('GET', "/files/{$first->id}", $token, $tenant)->assertOk();
        $this->api('GET', "/files/{$second->id}", $token, $tenant)->assertStatus(403);
    }

    public function test_access_of_another_school_disabled_courses_and_unpublished_files_grant_nothing(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $src = $this->existingCourse('SRC');
        $ubi = $this->existingCourse('UBI');
        // Zugang und Regel nur in der anderen Schule.
        $this->grantEntitlement($other, $user, $src);
        $this->rule($other, $src, 'downloads', 'free');
        $srcFile = $this->courseFile($src, 'download');
        // Kurs in der eigenen Schule gesperrt.
        $this->grantEntitlement($tenant, $user, $ubi);
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $ubi->id]));
        $ubiFile = $this->courseFile($ubi, 'download');
        $draft = $this->courseFile($src, 'download', ['published' => false]);
        $token = $this->apiLogin($user);

        $this->api('GET', "/files/{$srcFile->id}", $token, $tenant)->assertStatus(403);
        $this->api('GET', "/files/{$srcFile->id}", $token, $other)->assertOk();
        $this->api('GET', "/files/{$ubiFile->id}", $token, $tenant)->assertNotFound();
        $this->api('GET', "/files/{$draft->id}", $token, $other)->assertNotFound();
        $this->api('GET', '/files/'.Str::uuid(), $token, $other)->assertNotFound();
        $this->api('GET', '/files/not-a-uuid', $token, $other)->assertNotFound();
    }

    public function test_storage_paths_outside_the_course_file_directory_are_rejected(): void
    {
        $course = $this->existingCourse('SRC');
        foreach (['../.env', 'course-files/../../.env', '/etc/passwd', 'https://example.org/a.pdf', 'navigation-charts/a.png', 'course-files/a\\..\\b'] as $path) {
            try {
                $this->onAdmin(fn () => DB::transaction(fn () => CourseFile::create([
                    'course_id' => $course->id, 'category' => 'download', 'title' => 'x', 'storage_path' => $path,
                ])));
                $this->fail("Pfad {$path} wurde angenommen.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('course_file_path_check', $e->getMessage());
            }
        }

        // Datei fehlt auf der Disk: 404 statt Fehler.
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $user, $course);
        $file = $this->courseFile($course, 'download');
        Storage::disk(CourseFile::DISK)->delete($file->storage_path);
        $this->api('GET', "/files/{$file->id}", $user, $tenant)->assertNotFound();
    }

    public function test_the_app_role_can_only_read_course_files(): void
    {
        $course = $this->existingCourse('SRC');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/');
        CourseFile::create(['course_id' => $course->id, 'category' => 'download', 'title' => 'x', 'storage_path' => 'course-files/x.pdf']);
    }

    public function test_question_images_carry_a_checked_download_url(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $stranger = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $module = $this->onAdmin(fn () => $course->modules()->orderBy('course_module.sort_order')->firstOrFail());
        [, $revision] = $this->anyPublishedQuestionIn($module);
        Storage::disk('public')->put('question-media/test.png', 'PNG');
        $asset = $this->onAdmin(fn () => MediaAsset::create([
            'asset_key' => 'test-'.Str::uuid(), 'media_type' => 'image', 'storage_path' => 'question-media/test.png',
            'mime_type' => 'image/png', 'rights_status' => 'licensed', 'status' => 'published',
        ]));
        $this->onAdmin(fn () => $revision->media()->attach($asset->id, ['role' => 'question', 'sort_order' => 1]));

        try {
            $this->grantEntitlement($tenant, $learner, $course);
            $media = collect($this->onAdmin(fn () => QuestionPresenter::question($revision->fresh())['prompt_media']))->firstWhere('file_id', $asset->id);
            $this->assertSame(route('api.v1.files.download', ['fileId' => $asset->id]), $media['download_url']);
            // Die bisherige Bild-URL bleibt unverändert.
            $this->assertStringEndsWith('/question-media/test.png', $media['url']);

            $this->api('GET', "/files/{$asset->id}", $learner, $tenant)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->api('GET', "/files/{$asset->id}", $stranger, $tenant)->assertNotFound();

            // Prüfungszugang genügt ebenfalls.
            $this->partialEntitlement($tenant, $stranger, $course, ['exam']);
            $this->api('GET', "/files/{$asset->id}", $stranger, $tenant)->assertOk();

            // Nicht freigegebene Medien gibt es nicht.
            $this->onAdmin(fn () => $asset->update(['rights_status' => 'review']));
            $this->api('GET', "/files/{$asset->id}", $learner, $tenant)->assertNotFound();
        } finally {
            $this->onAdmin(function () use ($revision, $asset) {
                $revision->media()->detach($asset->id);
                $asset->delete();
            });
        }
    }

    public function test_import_copies_files_to_the_private_disk_and_updates_on_rerun(): void
    {
        $course = $this->existingCourse('SRC');
        $root = sys_get_temp_dir().'/course-files-'.Str::uuid();
        mkdir($root.'/wp', 0777, true);
        file_put_contents($root.'/wp/bogen.pdf', '%PDF-1.4 bogen');
        file_put_contents($root.'/secret.txt', 'geheim');
        $manifest = $root.'/manifest.json';
        $write = fn (array $files) => file_put_contents($manifest, json_encode(['files' => $files]));
        $row = ['course_code' => 'SRC', 'source_key' => 'wp-'.Str::uuid(), 'file' => 'bogen.pdf', 'category' => 'download',
            'title' => 'Übungsbogen', 'sha256' => hash('sha256', '%PDF-1.4 bogen'), 'mime_type' => 'application/pdf'];

        try {
            $write([$row]);
            $this->artisan('app:import-mobile-course-files', ['manifest' => $manifest, 'source-root' => $root.'/wp'])->assertSuccessful();
            $file = $this->onAdmin(fn () => CourseFile::where('course_id', $course->id)->where('source_key', $row['source_key'])->firstOrFail());
            $this->fileIds[] = $file->id;
            $this->assertStringStartsWith('course-files/SRC/', $file->storage_path);
            $this->assertSame('%PDF-1.4 bogen', Storage::disk(CourseFile::DISK)->get($file->storage_path));
            $this->assertTrue($file->published);

            $write([['title' => 'Neuer Titel'] + $row]);
            $this->artisan('app:import-mobile-course-files', ['manifest' => $manifest, 'source-root' => $root.'/wp'])->assertSuccessful();
            $this->assertSame(1, $this->onAdmin(fn () => CourseFile::where('course_id', $course->id)->where('source_key', $row['source_key'])->count()));
            $this->assertSame('Neuer Titel', $this->onAdmin(fn () => $file->fresh()->title));

            // Dateien außerhalb des Quellverzeichnisses, falsche Prüfsumme, unbekannte Kategorie.
            foreach ([['file' => '../secret.txt'], ['sha256' => str_repeat('a', 64)], ['category' => 'other'], ['mime_type' => 'text/html; x'], ['title' => '']] as $bad) {
                $write([$bad + $row]);
                $this->artisan('app:import-mobile-course-files', ['manifest' => $manifest, 'source-root' => $root.'/wp'])->assertFailed();
            }
            $this->assertSame('Neuer Titel', $this->onAdmin(fn () => $file->fresh()->title));
        } finally {
            @unlink($root.'/wp/bogen.pdf');
            @unlink($root.'/secret.txt');
            @unlink($manifest);
            @rmdir($root.'/wp');
            @rmdir($root);
        }
    }
}
