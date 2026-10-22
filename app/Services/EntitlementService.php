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
        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        return Entitlement::with('course.modules')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->currentlyActive()
            ->get()
            // Bug-Review B21: course_definition.status (draft/published/
            // archived) wurde hier bisher ignoriert -- ein noch nicht
            // veröffentlichter oder bereits archivierter Kurs blieb über ein
            // bestehendes Entitlement trotzdem zugänglich, genau wie
            // site_enabled wird jetzt auch status === 'published' geprüft.
            ->filter(fn (Entitlement $e) => $e->course && $e->course->status === 'published' && $e->course->site_enabled && ! $disabledCourseIds->contains($e->course_id))
            ->values();
    }

    public function hasAccess(Tenant $tenant, User $user, CourseDefinition $course): bool
    {
        return $this->activeCourses($tenant, $user)->contains('id', $course->id);
    }

    /** Ob ein Kurs für diese Bootsschule aktuell angeboten werden kann (veröffentlicht, sitewide UND mandantenseitig aktiv). */
    public function isOfferable(Tenant $tenant, CourseDefinition $course): bool
    {
        // Bug-Review B21: siehe activeEntitlements() -- ein Kurs im Status
        // draft oder archived wurde bisher trotzdem als anbietbar
        // behandelt, ließ sich also z. B. per Coupon einlösen oder manuell
        // freischalten, obwohl er fachlich noch nicht fertig oder bereits
        // zurückgezogen ist.
        if ($course->status !== 'published' || ! $course->site_enabled) {
            return false;
        }

        return ! TenantCourseDisabled::where('tenant_id', $tenant->id)->where('course_id', $course->id)->exists();
    }
}
