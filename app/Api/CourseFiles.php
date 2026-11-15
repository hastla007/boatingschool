<?php

namespace App\Api;

use App\Models\CourseDefinition;
use App\Models\CourseFile;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kursdateien und Fragenbilder der Mobile-API: welche Dateien eine Person in
 * der aktuellen Schule abrufen darf. Kursdetail (Liste) und Download
 * (GET /files/{id}) nutzen dieselben Regeln:
 *
 *  - Kategorie download: Funktion „downloads“ (voller Kurszugang, Entitlement
 *    mit Capability downloads oder Schulregel; free_limited = die ersten N
 *    Downloads in Kursreihenfolge).
 *  - Andere Dateien mit required_capability: diese Funktion (ebenfalls mit
 *    „erste N“ je Funktion).
 *  - Ohne required_capability: voller Kurszugang.
 *  - Fragenbilder: die Frage ist für die Person freigegeben (Fragenkatalog,
 *    inkl. Probezugang und Drip) oder sie hat Prüfungszugang im Kurs.
 */
class CourseFiles
{
    public function __construct(private CourseAccess $access) {}

    /**
     * Veröffentlichte Dateien eines Kurses in fester Reihenfolge.
     *
     * @return Collection<int, CourseFile>
     */
    public function published(CourseDefinition $course): Collection
    {
        return CourseFile::where('course_id', $course->id)->where('published', true)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * IDs der abrufbaren Dateien als Schlüssel.
     *
     * @param  Collection<int, CourseFile>  $files  vollständige Liste aus published()
     * @return Collection<string, int>
     */
    public function allowedIds(Tenant $tenant, User $user, CourseDefinition $course, Collection $files): Collection
    {
        $allowed = collect();
        foreach ($files->groupBy(fn (CourseFile $file) => $file->capability() ?? '') as $capability => $group) {
            $allowed = $allowed->merge($capability === ''
                ? ($this->access->hasCourseAccess($tenant, $user, $course) ? $group : collect())
                : $this->access->limitItems($tenant, $user, $course, (string) $capability, $group->values()));
        }

        return $allowed->pluck('id')->flip();
    }

    /** Plattformkurs, den die Schule anbietet (sonst null). */
    public function offeredCourse(Tenant $tenant, string $courseId): ?CourseDefinition
    {
        return CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('status', 'published')->where('site_enabled', true)
            ->whereNotIn('id', TenantCourseDisabled::where('tenant_id', $tenant->id)->select('course_id'))
            ->find($courseId);
    }

    /** Darf die Person das Fragenbild abrufen? */
    public function allowsMedia(Tenant $tenant, User $user, MediaAsset $asset): bool
    {
        // Fragen, an deren (veröffentlichter oder abgelöster) Revision das Bild hängt.
        $questionIds = DB::table('question_media')
            ->join('content_question_revision', 'content_question_revision.id', '=', 'question_media.revision_id')
            ->where('question_media.media_asset_id', $asset->id)
            ->whereIn('content_question_revision.editorial_status', ['published', 'deprecated'])
            ->distinct()->pluck('content_question_revision.question_id');
        if ($questionIds->isEmpty()) {
            return false;
        }

        $catalogCourses = DB::table('module_content')
            ->join('course_module', 'course_module.module_id', '=', 'module_content.module_id')
            ->whereIn('module_content.question_id', $questionIds)
            ->distinct()->pluck('course_module.course_id');
        $paperCourses = DB::table('exam_paper_question')
            ->join('exam_paper', 'exam_paper.id', '=', 'exam_paper_question.exam_paper_id')
            ->whereIn('exam_paper_question.question_id', $questionIds)
            ->distinct()->pluck('exam_paper.course_id');

        foreach ($catalogCourses->merge($paperCourses)->unique() as $courseId) {
            $course = $this->offeredCourse($tenant, (string) $courseId);
            if (! $course) {
                continue;
            }
            if ($this->access->hasFeatureAccess($tenant, $user, $course, 'exam')
                || $this->access->accessibleQuestionIds($tenant, $user, $course)->intersect($questionIds)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }
}
