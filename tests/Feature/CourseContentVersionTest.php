<?php

namespace Tests\Feature;

use App\Api\CourseAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit ARC-V2: die Inhaltsversion (Schlüssel der Kurs-Zwischenspeicher der
 * App) wurde mit Lesen + Schreiben erhöht (nicht atomar) und nur bei
 * Änderungen im Superadmin-Bereich -- Importe auf der Konsole ließen die
 * App bis zu einer Stunde alte Fragen sehen.
 */
class CourseContentVersionTest extends TestCase
{
    use InteractsWithTenants;

    private function version(): int
    {
        return (int) Cache::get(CourseAccess::CONTENT_VERSION_KEY, 1);
    }

    public function test_bumping_increments_atomically_in_the_shared_cache(): void
    {
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        try {
            Cache::forget(CourseAccess::CONTENT_VERSION_KEY);
            CourseAccess::bumpContentVersion();
            $this->assertSame(2, $this->version());

            // Ein zweiter Prozess erhöht zwischendurch (eigene Cache-Instanz):
            // die eigene, im Request gemerkte Version darf nicht zurückschreiben.
            $course = $this->existingCourse('SRC');
            $this->assertStringContainsString(':v2:', CourseAccess::cacheKey('data', $course));
            app('cache')->store('database')->increment(CourseAccess::CONTENT_VERSION_KEY);
            $locked = false;
            DB::listen(function ($query) use (&$locked) {
                $locked = $locked || (str_contains($query->sql, 'from "cache"') && str_contains($query->sql, 'for update'));
            });
            CourseAccess::bumpContentVersion();
            $this->assertSame(4, $this->version());
            $this->assertTrue($locked, 'Erhöhen unter Zeilensperre (Cache::increment), nicht Lesen + Überschreiben');
            $this->assertStringContainsString(':v4:', CourseAccess::cacheKey('data', $course));
        } finally {
            DB::connection('pgsql_admin')->table('cache')->where('key', 'like', '%'.CourseAccess::CONTENT_VERSION_KEY.'%')->delete();
        }
    }

    public function test_cli_content_changes_bump_the_version(): void
    {
        $file = storage_path('app/arc-v2-rules-'.uniqid().'.json');
        $csv = storage_path('app/arc-v2-feedback-'.uniqid().'.csv');
        file_put_contents($file, json_encode(['rules' => []]));
        file_put_contents($csv, "content_id;feedback_correct;feedback_incorrect\n");
        try {
            $start = $this->version();
            $this->artisan('app:import-mobile-access-rules', ['file' => $file])->assertSuccessful();
            $this->assertSame($start + 1, $this->version());

            $this->assertSame(0, Artisan::call('content:import-feedback', ['path' => 'storage/app/'.basename($csv)]));
            $this->assertSame($start + 2, $this->version());
        } finally {
            @unlink($file);
            @unlink($csv);
        }
    }
}
