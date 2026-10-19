<?php

namespace App\Support;

/**
 * Kombikurse bündeln die Videokapitel mehrerer Teilkurse unter einer
 * gemeinsamen Kurs-ID (siehe ComboVideoCourseSeeder). Diese Zuordnung von
 * Kapitelnamen zu Teilkurs-Label wird sowohl für die Fortschrittskarten auf
 * der Kursdetailseite (CourseController) als auch für den Videoplayer
 * (VideoCourseController) gebraucht, daher zentral an einer Stelle.
 */
class ComboVideoGroups
{
    public const GROUPS = [
        'SBF-SEE-BIN' => [
            ['label' => 'SBF See', 'chapters' => [
                'Einführung in den Sportbootführerschein', 'Seemannschaft',
                'Praxisvideos (Motor)', 'Navigation', 'Wetterkunde', 'Ausweichregeln', 'Lichter und Signale',
            ]],
            ['label' => 'SBF Binnen', 'chapters' => [
                'Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik',
            ]],
        ],
    ];

    /** Teilkurs-Label für ein gegebenes Videokapitel, oder null außerhalb eines Kombikurses. */
    public static function labelForChapter(string $courseCode, string $chapterTitle): ?string
    {
        foreach (self::GROUPS[$courseCode] ?? [] as $group) {
            if (in_array($chapterTitle, $group['chapters'], true)) {
                return $group['label'];
            }
        }

        return null;
    }
}
