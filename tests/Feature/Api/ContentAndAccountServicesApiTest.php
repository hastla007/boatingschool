<?php

namespace Tests\Feature\Api;

use App\Models\ApiDataExport;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\NavigationTask;
use App\Models\VideoLesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Mobile-API: Videokurs, Navigationstrainer, Zugangscodes und Datenexport. */
class ContentAndAccountServicesApiTest extends MobileApiTestCase
{
    private array $couponIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            Coupon::whereIn('id', $this->couponIds)->delete();
            foreach ($this->createdUserIds as $id) {
                DB::table('api_data_export')->where('user_id', $id)->delete();
            }
        });
        parent::tearDown();
    }

    public function test_video_modules_lessons_with_placeholders_and_progress(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $modules = $this->api('GET', "/courses/{$course->id}/videos", $token, $tenant)->assertOk()->json('data');
        $this->assertNotEmpty($modules);
        $lesson = $modules[0]['lessons'][0];
        // Platzhaltervideo der Seeder: sichtbar, aber ohne abspielbare URL.
        $this->assertSame('placeholder', $lesson['media']['kind']);
        $this->assertNull($lesson['media']['url']);
        $this->assertFalse($lesson['completed']);

        $this->api('GET', "/videos/{$lesson['id']}", $token, $tenant)->assertOk()->assertJsonStructure(['data' => ['id', 'title', 'media', 'steps']]);
        $this->api('PUT', "/videos/{$lesson['id']}/progress", $token, $tenant, ['position_seconds' => 42, 'completed' => true])
            ->assertOk()->assertJsonPath('data.completed', true);
        // Abgeschlossen bleibt abgeschlossen, die Position wird weiter gemerkt.
        $this->api('PUT', "/videos/{$lesson['id']}/progress", $token, $tenant, ['position_seconds' => 5, 'completed' => false])
            ->assertOk()->assertJsonPath('data.completed', true)->assertJsonPath('data.position_seconds', 5);
        $this->api('PUT', "/videos/{$lesson['id']}/progress", $token, $tenant, ['position_seconds' => -1, 'completed' => false])->assertStatus(422);

        // Lektion eines Kurses ohne Zugang.
        $foreignLesson = $this->onAdmin(fn () => VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $this->existingCourse('SRC-UBI')->id))->value('id'));
        $this->api('GET', "/videos/{$foreignLesson}", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_navigation_trainer_shows_solution_on_request_and_checks_answers(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $list = $this->api('GET', "/courses/{$course->id}/navigation-tasks", $token, $tenant)->assertOk()->assertJsonPath('meta.pagination.total', 15);
        $taskId = $list->json('data.0.id');
        $this->assertSame('new', $list->json('data.0.status'));

        $task = $this->api('GET', "/navigation-tasks/{$taskId}", $token, $tenant)->assertOk();
        $this->assertNull($task->json('data.parts.0.solution'));
        $withSolution = $this->api('GET', "/navigation-tasks/{$taskId}?show_solution=true", $token, $tenant)->assertOk();
        $this->assertNotNull($withSolution->json('data.parts.0.solution'));

        $part = collect($withSolution->json('data.parts'))->firstWhere('auto_checkable', true);
        $this->api('PUT', "/navigation-tasks/{$taskId}/parts/{$part['id']}/answer", $token, $tenant, ['answer' => $part['solution']])
            ->assertOk()->assertJsonPath('data.correct', true)->assertJsonPath('data.auto_checked', true);
        $this->api('PUT', "/navigation-tasks/{$taskId}/parts/{$part['id']}/answer", $token, $tenant, ['self_assessment' => 'incorrect'])
            ->assertOk()->assertJsonPath('data.correct', false);
        $this->api('PUT', "/navigation-tasks/{$taskId}/parts/{$part['id']}/answer", $token, $tenant, [])->assertStatus(422);
        $this->assertSame('started', $this->api('GET', "/courses/{$course->id}/navigation-tasks", $token, $tenant)->json('data.0.status'));

        // Aufgabe eines Kurses ohne Zugang.
        $foreign = $this->onAdmin(fn () => NavigationTask::where('course_id', $this->existingCourse('ADD-BIN-TO-SEE')->id)->value('id'));
        $this->api('GET', "/navigation-tasks/{$foreign}", $token, $tenant)->assertStatus(403);
    }

    public function test_coupon_redemption_is_uniform_on_failure_and_idempotent_for_the_owner(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $code = 'APP-'.Str::upper(Str::random(12));
        $this->couponIds[] = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id])->id);
        $token = $this->apiLogin($user);

        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => 'GIBT-ES-NICHT'])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => ' '.strtolower($code).' '])->assertOk()
            ->assertJsonPath('data.redeemed', true)->assertJsonPath('data.already_redeemed', false)
            ->assertJsonPath('data.type', 'course')->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.entitlement.status', 'active');
        $this->assertTrue($this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->exists()));

        // Noch einmal (z. B. nach Verbindungsabbruch, neuer Schlüssel): gleiches Ergebnis.
        $this->api('POST', '/coupons/redeem', $token, $tenant, ['code' => $code])->assertOk()->assertJsonPath('data.already_redeemed', true);
        // Für andere: dieselbe Meldung wie ein unbekannter Code.
        $this->api('POST', '/coupons/redeem', $other, $tenant, ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');

        // Gesperrt: ebenfalls COUPON_INVALID.
        $voided = 'APP-'.Str::upper(Str::random(12));
        $this->couponIds[] = $this->onAdmin(fn () => Coupon::create(['code' => $voided, 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'voided_at' => now()])->id);
        $this->api('POST', '/coupons/redeem', $other, $tenant, ['code' => $voided])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
    }

    public function test_data_export_gives_a_short_lived_owner_bound_download(): void
    {
        Storage::fake('local');
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        $requested = $this->api('POST', '/profile/export', $token, $tenant)->assertStatus(202)->assertJsonPath('data.status', 'ready');
        $exportId = $requested->json('data.export_id');
        $url = $this->api('GET', "/profile/export/{$exportId}", $token, $tenant)->assertOk()->json('data.download_url');
        $this->assertNotNull($url);
        $this->api('GET', "/profile/export/{$exportId}", $other, $tenant)->assertNotFound();

        $download = $this->get($url)->assertOk();
        $this->assertSame($user->email, json_decode($download->streamedContent(), true)['profile']['email']);
        // Manipulierter Link: abgelehnt.
        $this->get($url.'x')->assertStatus(403);

        // Abgelaufen: kein Link mehr, Download 410.
        $this->onAdmin(fn () => ApiDataExport::where('id', $exportId)->update(['expires_at' => now()->subMinute()]));
        $this->api('GET', "/profile/export/{$exportId}", $token, $tenant)->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.download_url', null);
        // In der Auskunft der Person: ihre angemeldeten App-Geräte.
        $this->assertNotEmpty(json_decode($download->streamedContent(), true)['app_devices']);
    }

    public function test_prune_removes_old_app_sessions_idempotency_keys_and_exports(): void
    {
        Storage::fake('local');
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $current = $this->apiLogin($user);
        $exportId = $this->api('POST', '/profile/export', $token, $tenant)->json('data.export_id');
        $this->assertCount(1, Storage::disk('local')->files('api-exports'));
        $this->onAdmin(function () use ($user, $token) {
            DB::table('api_device_session')->where('user_id', $user->id)->where('access_token_hash', hash('sha256', $token))
                ->update(['revoked_at' => now()->subDays(31)]);
            DB::table('api_idempotency_key')->where('user_id', $user->id)->update(['created_at' => now()->subDays(2)]);
        });

        $this->travel(2)->hours();
        $this->artisan('app:prune-personal-data')->assertSuccessful();

        $this->onAdmin(function () use ($user, $exportId) {
            $this->assertSame(1, DB::table('api_device_session')->where('user_id', $user->id)->count(), 'aktive Sitzung bleibt');
            $this->assertSame(0, DB::table('api_idempotency_key')->where('user_id', $user->id)->count());
            $this->assertFalse(DB::table('api_data_export')->where('id', $exportId)->exists());
        });
        $this->assertCount(0, Storage::disk('local')->files('api-exports'));
        $this->travelBack();
        $this->api('GET', '/me', $current)->assertOk();
    }
}
