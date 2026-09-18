<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Ein Nutzer kann mehrere Produkte kaufen; effektive Berechtigung ist die
 * Vereinigung aktiver Entitlements." Zeitliche Gültigkeit und Status werden
 * hier zentral geprüft, damit Ablauf/Suspendierung überall sofort wirkt.
 */
class EntitlementService
{
    /** @return Collection<int, CourseDefinition> */
    public function activeCourses(Tenant $tenant, User $user): Collection
    {
        return $this->activeEntitlements($tenant, $user)
            ->map(fn (Entitlement $e) => $e->course)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, Entitlement> */
    public function activeEntitlements(Tenant $tenant, User $user): Collection
    {
        $now = now();
        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        return Entitlement::with('course.modules')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('valid_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->get()
            ->filter(fn (Entitlement $e) => $e->course && $e->course->site_enabled && ! $disabledCourseIds->contains($e->course_id))
            ->values();
    }

    public function hasAccess(Tenant $tenant, User $user, CourseDefinition $course): bool
    {
        return $this->activeCourses($tenant, $user)->contains('id', $course->id);
    }

    /** Ob ein Kurs für diese Bootsschule aktuell angeboten werden kann (sitewide UND mandantenseitig aktiv). */
    public function isOfferable(Tenant $tenant, CourseDefinition $course): bool
    {
        if (! $course->site_enabled) {
            return false;
        }

        return ! TenantCourseDisabled::where('tenant_id', $tenant->id)->where('course_id', $course->id)->exists();
    }
}
