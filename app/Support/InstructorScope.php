<?php

namespace App\Support;

use App\Models\Entitlement;
use App\Models\InstructorCourse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sichtbarkeit für Ausbilder: sind einem Ausbilder Kurse zugeordnet, sieht
 * er im Admin-Bereich nur Lernende mit einem Zugang zu diesen Kursen (und
 * deren Praxis-Anmeldungen). Inhaber, Admins und Ausbilder ohne Zuordnung
 * sehen alles.
 */
class InstructorScope
{
    /** @return Collection<int, string>|null Kurs-IDs oder null = keine Einschränkung */
    public static function courseIds(Tenant $tenant, User $user): ?Collection
    {
        if ($user->roleForTenant($tenant) !== 'instructor') {
            return null;
        }
        $ids = InstructorCourse::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('course_id');

        return $ids->isEmpty() ? null : $ids;
    }

    /** @return Collection<int, string>|null Nutzer-IDs der sichtbaren Lernenden oder null = alle */
    public static function learnerIds(Tenant $tenant, User $user): ?Collection
    {
        return self::learnerIdsForCourses($tenant, self::courseIds($tenant, $user));
    }

    /**
     * Wie learnerIds(), aber mit bereits ermittelten courseIds() -- spart
     * Seiten, die beides brauchen, die zweite Rollen- und Zuordnungsabfrage.
     *
     * @param  Collection<int, string>|null  $courseIds  Ergebnis von courseIds()
     * @return Collection<int, string>|null
     */
    public static function learnerIdsForCourses(Tenant $tenant, ?Collection $courseIds): ?Collection
    {
        return $courseIds === null ? null
            : Entitlement::where('tenant_id', $tenant->id)->whereIn('course_id', $courseIds)->distinct()->pluck('user_id');
    }

    public static function canSeeLearner(Tenant $tenant, User $actor, string $learnerId): bool
    {
        $ids = self::learnerIds($tenant, $actor);

        return $ids === null || $ids->contains($learnerId);
    }
}
