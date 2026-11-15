<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * Von der Schule abgeschaltete Kurse (tenant_course_disabled), je Request
 * und Schule nur einmal geladen. Audit ARC-5: Kursliste/Kursdetail der App
 * fragten die Liste bis zu fünfmal je Request ab (Controller, Zugänge,
 * CourseAccess). Ohne Request (Konsole, Queue) wird nicht zwischengespeichert.
 */
class DisabledCourses
{
    /** @var WeakMap<Request, array<string, Collection<int, string>>> */
    private WeakMap $memo;

    public function __construct()
    {
        $this->memo = new WeakMap;
    }

    /** @return Collection<int, string> Kurs-IDs */
    public function ids(Tenant|string $tenant): Collection
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return $this->load($tenantId);
        }

        $cached = $this->memo[$request] ?? [];
        if (! isset($cached[$tenantId])) {
            $cached[$tenantId] = $this->load($tenantId);
            $this->memo[$request] = $cached;
        }

        return $cached[$tenantId];
    }

    public function contains(Tenant|string $tenant, string $courseId): bool
    {
        return $this->ids($tenant)->contains($courseId);
    }

    /** Nach Änderungen im selben Request neu laden. */
    public function forget(): void
    {
        $this->memo = new WeakMap;
    }

    /** @return Collection<int, string> */
    private function load(string $tenantId): Collection
    {
        return TenantCourseDisabled::where('tenant_id', $tenantId)->pluck('course_id');
    }
}
