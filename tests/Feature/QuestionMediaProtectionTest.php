<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\PraxisTask;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\ProductionReadiness;
use App\Support\ProtectedMedia;
use App\Support\QuestionMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lizenzierte bzw. amtliche Fragenbilder liegen privat und sind nur über
 * signierte, an Person und Schule gebundene Adressen abrufbar
 * (QuestionMedia, QuestionMediaController, Migration 000630).
 */
class QuestionMediaProtectionTest extends TestCase
{
    use InteractsWithTenants;

    /** @var list<string> */
    private array $questionIds = [];

    /** @var list<string> */
    private array $assetIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $revisionIds = ContentQuestionRevision::whereIn('question_id', $this->questionIds)->pluck('id');
            DB::table('question_media')->whereIn('revision_id', $revisionIds)->delete();
            DB::table('module_content')->whereIn('question_id', $this->questionIds)->delete();
            ContentQuestionRevision::whereIn('id', $revisionIds)->delete();
            ContentQuestion::whereIn('id', $this->questionIds)->delete();
            MediaAsset::whereIn('id', $this->assetIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_learning_page_shows_a_working_signed_image_and_the_old_public_address_is_gone(): void
    {
        [$tenant, $learner, $course] = $this->learnerWithCourse('SBF-SEE');
        [$question, $asset] = $this->elwisQuestion('SBF_BASIS', '4');

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=smarttrainer&questions={$question->id}")->assertOk();
        $signed = $this->imageUrl($page->getContent(), $asset);

        $image = $this->get($signed)->assertOk();
        $this->assertSame('image/png', $image->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $image->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $this->assertSame(ProtectedMedia::SANDBOX_CSP, $image->headers->get('Content-Security-Policy'));

        // Ohne Signatur bzw. mit verändertem Parameter: abgelehnt.
        $this->get(strtok($signed, '?'))->assertForbidden();
        $this->get(preg_replace('/u=[0-9a-f-]+/', 'u='.Str::uuid(), $signed))->assertForbidden();

        // Die alte öffentliche Adresse gibt es nicht mehr.
        $path = $asset->getRawOriginal('storage_path');
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->get('/storage/'.$path)->assertNotFound();

        // Abgelaufen.
        $this->travel(QuestionMedia::URL_MINUTES + 1)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();
    }

    public function test_exam_page_shows_a_working_signed_image(): void
    {
        [$tenant, $learner, $course] = $this->learnerWithCourse('SBF-SEE');
        // Bogen 4 beginnt mit einer Bildfrage.
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 4)->firstOrFail());
        $show = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect()->headers->get('Location');

        $page = $this->actingAsInTenant($learner, $tenant)->get($show)->assertOk();
        preg_match('#src="([^"]*/media/question-media/[^"]+)"#', $page->getContent(), $m);
        $this->assertNotEmpty($m, 'signierte Bildadresse auf der Prüfungsseite');
        $this->assertStringNotContainsString('/storage/question-media/', $page->getContent());
        $this->get(html_entity_decode($m[1]))->assertOk();
    }

    public function test_other_school_people_without_course_access_and_suspended_members_get_nothing(): void
    {
        [$tenant, $learner] = $this->learnerWithCourse('SBF-SEE');
        [, $asset] = $this->elwisQuestion('SBF_BASIS', '4');

        // Andere Schule ohne Zugang.
        $other = $this->createTestTenant();
        $stranger = $this->createTenantUser($other, 'learner');
        $this->get(QuestionMedia::url($asset, $stranger, $other))->assertNotFound();

        // Eigene Person, aber mit fremder Schule signiert (dort kein Mitglied).
        $this->get(QuestionMedia::url($asset, $learner, $other))->assertNotFound();
        // Ohne Schule.
        $this->get(QuestionMedia::url($asset, $learner, null))->assertNotFound();

        // Kurs ohne dieses Bild (FKN).
        $funker = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $funker, $this->existingCourse('FKN'));
        $this->get(QuestionMedia::url($asset, $funker, $tenant))->assertNotFound();

        // Mit Zugang erlaubt, nach Sperre der Mitgliedschaft nicht mehr (auch mit noch gültiger Adresse).
        $url = QuestionMedia::url($asset, $learner, $tenant);
        $this->get($url)->assertOk();
        $this->onAdmin(fn () => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $learner->id)->update(['status' => 'suspended']));
        $this->get($url)->assertNotFound();

        // Superadmin immer, auch ohne Schule.
        $this->get(QuestionMedia::url($asset, $this->createSuperAdmin(), null))->assertOk();
    }

    public function test_praxistrainer_images_follow_the_course_of_the_card(): void
    {
        [$tenant, $learner, $course] = $this->learnerWithCourse('SBF-SEE');
        $task = $this->onAdmin(fn () => PraxisTask::with('media')->where('course_id', $course->id)->where('active', true)
            ->whereHas('media', fn ($q) => $q->where('source', 'SBF See Praxistrainer'))->firstOrFail());

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer/{$task->id}")->assertOk();
        $this->assertStringNotContainsString('/storage/question-media/', $page->getContent());
        $this->get($this->imageUrl($page->getContent(), $task->media))->assertOk();

        $funker = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $funker, $this->existingCourse('FKN'));
        $this->get(QuestionMedia::url($task->media, $funker, $tenant))->assertNotFound();
    }

    public function test_superseded_revisions_keep_their_images_drafts_do_not_count(): void
    {
        [$tenant, $learner] = $this->learnerWithCourse('SBF-SEE');
        Storage::disk('local')->put('question-media/test/'.($name = Str::uuid().'.png'), $this->png());
        $asset = $this->asset('question-media/test/'.$name, 'licensed');

        $revision = $this->questionInModule('SBF_BASIS', 'deprecated', $asset);
        $url = QuestionMedia::url($asset, $learner, $tenant);
        $this->get($url)->assertOk();

        // Nur ein Entwurf nutzt das Bild: kein Zugang.
        $this->onAdmin(fn () => $revision->update(['editorial_status' => 'draft']));
        $this->get($url)->assertNotFound();

        Storage::disk('local')->delete('question-media/test/'.$name);
    }

    public function test_only_raster_images_are_shown_inline_and_caching_stays_short(): void
    {
        [$tenant, $learner] = $this->learnerWithCourse('SBF-SEE');
        $files = [];
        $responses = [];
        foreach (['png' => [$this->png(), 'image/png'], 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml']] as $extension => [$contents, $mime]) {
            Storage::disk('local')->put($files[$extension] = 'question-media/test/'.Str::uuid().'.'.$extension, $contents);
            $asset = $this->asset($files[$extension], 'licensed');
            $this->onAdmin(fn () => MediaAsset::whereKey($asset->id)->update(['mime_type' => $mime]));
            $this->questionInModule('SBF_BASIS', 'published', $asset);
            $responses[$extension] = $this->get(QuestionMedia::url($asset, $learner, $tenant))->assertOk();
        }

        $png = $responses['png'];
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $png->headers->get('Content-Disposition'));
        // Kurz zwischenspeichern: ein entzogener Zugang wirkt im Browser bald.
        $this->assertMatchesRegularExpression('/(^|\W)max-age=300(\D|$)/', (string) $png->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $png->headers->get('Cache-Control'));

        // SVG (kann Skript enthalten) nur als Download, nie inline.
        $svg = $responses['svg'];
        $this->assertSame('application/octet-stream', $svg->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $svg->headers->get('Content-Disposition'));

        Storage::disk('local')->delete(array_values($files));
    }

    public function test_public_media_stay_public_and_unchanged(): void
    {
        [$tenant, $learner] = $this->learnerWithCourse('SBF-SEE');
        $path = 'question-media/test/'.Str::uuid().'.png';
        Storage::disk('public')->put($path, $this->png());
        $asset = $this->asset($path, 'public');
        $this->questionInModule('SBF_BASIS', 'published', $asset);

        $this->assertFalse(QuestionMedia::isProtected($asset));
        $this->assertSame(Storage::disk('public')->url($path), QuestionMedia::url($asset, $learner, $tenant));
        // Die geschützte Route liefert öffentliche Medien nicht aus.
        $this->get(URL::temporarySignedRoute('question-media.file', now()->addMinute(), ['asset' => $asset->id, 'u' => $learner->id, 't' => $tenant->id]))->assertNotFound();

        // Die Migration lässt sie liegen.
        $this->runMigration('up');
        $this->assertTrue(Storage::disk('public')->exists($path));
        Storage::disk('public')->delete($path);
    }

    public function test_migration_moves_protected_files_idempotently_and_back(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $moved = 'question-media/test/'.Str::uuid().'.png';
        $missing = 'question-media/test/'.Str::uuid().'.png';
        Storage::disk('public')->put($moved, 'bild');
        Storage::disk('public')->put('branding-logos/logo.png', 'logo');
        $this->asset($moved, 'review');
        $this->asset($missing, 'licensed');

        $this->runMigration('up');
        Storage::disk('local')->assertExists($moved);
        Storage::disk('public')->assertMissing($moved);
        Storage::disk('public')->assertExists('branding-logos/logo.png');

        // Zweiter Lauf: nichts kaputt, nichts doppelt.
        $this->runMigration('up');
        $this->assertSame('bild', Storage::disk('local')->get($moved));
        Storage::disk('public')->assertMissing($moved);

        $this->runMigration('down');
        Storage::disk('public')->assertExists($moved);
        Storage::disk('local')->assertMissing($moved);
    }

    public function test_go_live_check_warns_about_protected_files_on_the_public_disk(): void
    {
        [, $asset] = $this->elwisQuestion('SBF_BASIS', '4');
        Storage::fake('public');
        $label = 'Geschützte Fragenbilder nicht öffentlich abrufbar';

        $this->assertSame('ok', collect(ProductionReadiness::checks())->firstWhere('label', $label)['status']);
        Storage::disk('public')->put($asset->getRawOriginal('storage_path'), 'bild');
        $check = collect(ProductionReadiness::checks())->firstWhere('label', $label);
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString($asset->getRawOriginal('storage_path'), $check['detail']);
    }

    public function test_paths_outside_the_disk_are_never_served(): void
    {
        $asset = new MediaAsset(['rights_status' => 'licensed']);
        $asset->setRawAttributes(['storage_path' => '../../.env', 'rights_status' => 'licensed']);
        $this->assertNull(QuestionMedia::locate($asset));
        $asset->setRawAttributes(['storage_path' => 'question-media/../../../.env', 'rights_status' => 'licensed']);
        $this->assertNull(QuestionMedia::locate($asset));
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function learnerWithCourse(string $code): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse($code);
        $this->grantEntitlement($tenant, $learner, $course);

        return [$tenant, $learner, $course];
    }

    /** @return array{0: ContentQuestion, 1: MediaAsset} */
    private function elwisQuestion(string $moduleCode, string $number): array
    {
        return $this->onAdmin(function () use ($moduleCode, $number) {
            $question = Module::where('code', $moduleCode)->firstOrFail()->questions()->where('official_number', $number)->firstOrFail();

            return [$question, $question->publishedRevision()->media()->firstOrFail()];
        });
    }

    private function asset(string $path, string $rights): MediaAsset
    {
        $asset = $this->onAdmin(fn () => MediaAsset::create([
            'asset_key' => 'TEST-QM-'.Str::random(12), 'media_type' => 'image', 'storage_path' => $path,
            'mime_type' => 'image/png', 'rights_status' => $rights, 'status' => 'published',
        ]));
        $this->assetIds[] = $asset->id;
        // Für die Migration: als Fragenbild verknüpft.
        if (! $this->onAdmin(fn () => DB::table('question_media')->where('media_asset_id', $asset->id)->exists())) {
            $this->questionInModule(null, 'published', $asset);
        }

        return $asset->fresh();
    }

    private function questionInModule(?string $moduleCode, string $status, MediaAsset $asset): ContentQuestionRevision
    {
        return $this->onAdmin(function () use ($moduleCode, $status, $asset) {
            DB::table('question_media')->where('media_asset_id', $asset->id)->delete();
            $question = ContentQuestion::create(['content_id' => 'TEST-QM-'.Str::random(12), 'official_number' => 'TEST-QM', 'language' => 'de-DE', 'active' => true]);
            $this->questionIds[] = $question->id;
            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id, 'revision_no' => 1, 'question_type' => 'single_choice',
                'question_text' => 'Testfrage mit Bild?', 'editorial_status' => $status, 'rights_status' => 'licensed',
            ]);
            $revision->media()->attach($asset->id, ['role' => 'question', 'sort_order' => 1]);
            if ($moduleCode) {
                DB::table('module_content')->insert(['module_id' => Module::where('code', $moduleCode)->value('id'), 'question_id' => $question->id, 'sort_order' => 9999]);
            }

            return $revision;
        });
    }

    private function imageUrl(string $html, MediaAsset $asset): string
    {
        preg_match('#src="([^"]*/media/question-media/'.$asset->id.'\?[^"]+)"#', $html, $m);
        $this->assertNotEmpty($m, 'signierte Bildadresse auf der Seite');

        return html_entity_decode($m[1]);
    }

    private function runMigration(string $direction): void
    {
        $migration = require database_path('migrations/2024_02_01_000630_move_protected_question_media_to_private_disk.php');
        $this->onAdmin(fn () => $migration->{$direction}());
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
