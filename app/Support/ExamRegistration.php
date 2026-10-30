<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\CourseExamRegistration;
use App\Models\PraxisFormSubmission;
use App\Models\Tenant;
use App\Models\User;

/**
 * Wer meldet den Teilnehmer zur amtlichen Prüfung an? Je Bootsschule und
 * Kurs einstellbar (Admin, Tab "Kurse"):
 *
 * - SCHOOL: die Bootsschule -- Teilnehmer nutzen das Termintool
 *   "Praxis & Prüfung" (PraxisPruefungController). Standard.
 * - SELF: der Teilnehmer selbst -- er sieht stattdessen die Seite
 *   "Prüfung buchen" mit Anmeldeweg, Gebühren und Ablauf des jeweiligen
 *   Kurses (ExamBooking). Nur für Kurse, für die es eine solche Seite gibt.
 */
class ExamRegistration
{
    public const SCHOOL = 'school';

    public const SELF = 'self';

    public static function hasSelfPage(CourseDefinition $course): bool
    {
        return ExamBooking::pagesFor($course) !== [];
    }

    public static function modeFor(Tenant $tenant, CourseDefinition $course): string
    {
        if (! self::hasSelfPage($course)) {
            return self::SCHOOL;
        }

        return CourseExamRegistration::where('tenant_id', $tenant->id)->where('course_id', $course->id)->value('mode') ?? self::SCHOOL;
    }

    public static function isSelf(Tenant $tenant, CourseDefinition $course): bool
    {
        return self::modeFor($tenant, $course) === self::SELF;
    }

    /**
     * Sieht dieser Teilnehmer das Termintool "Praxis & Prüfung"?
     *
     * Bug-Review (Umstellung auf Selbstanmeldung): stellt die Bootsschule
     * einen Kurs nachträglich von SCHOOL auf SELF um, verloren Teilnehmer
     * mit bereits abgeschickter Praxis-Anmeldung bisher jeden Zugang zu
     * ihrer Anmeldung, zu vorgeschlagenen Prüfungsterminen und ihren
     * Antworten darauf. Wer schon eine Anmeldung hat, behält das Termintool
     * deshalb; nur Teilnehmer ohne Anmeldung landen auf "Prüfung buchen".
     */
    public static function usesAppointmentTool(Tenant $tenant, CourseDefinition $course, User $user): bool
    {
        return ! self::isSelf($tenant, $course) || self::hasSubmission($tenant, $course, $user);
    }

    public static function hasSubmission(Tenant $tenant, CourseDefinition $course, User $user): bool
    {
        return PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();
    }
}
