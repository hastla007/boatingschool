<?php

namespace App\Console\Commands;

use App\Api\CourseAccess;
use App\Models\CourseDefinition;
use Illuminate\Console\Command;

/**
 * Berechnet die zwischengespeicherten Kursdaten der Kursliste (gültige
 * Fragen, Funktionen wie Prüfung/Video/Navigation) für alle Kurse vorab --
 * nach einem Deploy und stündlich, damit kein Lernender den ersten,
 * langsamen Aufruf abbekommt.
 */
class WarmCourseCache extends Command
{
    protected $signature = 'app:warm-course-cache';

    protected $description = 'Füllt den Zwischenspeicher der Kursliste (Mobile-API) für alle Kurse vorab.';

    public function handle(CourseAccess $access): int
    {
        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);

        try {
            $courses = CourseDefinition::withoutGlobalScopes()->where('status', 'published')->get();
            foreach ($courses as $course) {
                $access->warm($course);
            }
        } finally {
            config(['database.default' => $previous]);
        }
        $this->line("Kurs-Zwischenspeicher für {$courses->count()} Kurs(e) aufgebaut.");

        return self::SUCCESS;
    }
}
