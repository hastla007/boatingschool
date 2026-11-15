<?php

namespace Tests\Feature\Api;

use App\Models\NavigationTask;
use App\Support\NavigationChart;
use Illuminate\Support\Facades\Storage;

/** Mobile-API: Kartenausschnitte kommen als signierte, an Person und Schule gebundene `url`. */
class NavigationChartApiTest extends MobileApiTestCase
{
    public function test_navigation_task_chart_url_is_bound_to_person_and_school(): void
    {
        Storage::fake('local');
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $entitlement = $this->grantEntitlement($tenant, $user, $course);
        $task = $this->onAdmin(fn () => NavigationTask::where('course_id', $course->id)->orderBy('sort_order')->firstOrFail());
        $original = [$task->chart_image_path, $task->chart_caption];
        $path = NavigationChart::DIRECTORY.'/'.$task->id.'-api-test.png';
        Storage::disk('local')->put($path, 'png');
        $this->onAdmin(fn () => $task->update(['chart_image_path' => $path]));

        try {
            $token = $this->apiLogin($user);
            $url = $this->api('GET', "/navigation-tasks/{$task->id}", $token, $tenant)->assertOk()->json('data.chart.url');
            $this->assertMatchesRegularExpression('#/media/navigation-charts/[^?]+\?.*signature=#', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($user->id, $query['u'] ?? null);
            $this->assertSame($tenant->id, $query['t'] ?? null);

            // Ohne Token ladbar (Bild-Loader), Zugang wird beim Abruf erneut geprüft.
            $this->get($url)->assertOk();
            $this->onAdmin(fn () => $entitlement->update(['status' => 'revoked']));
            $this->get($url)->assertNotFound();
        } finally {
            $this->onAdmin(fn () => $task->update(['chart_image_path' => $original[0], 'chart_caption' => $original[1]]));
        }
    }
}
