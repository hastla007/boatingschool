<?php

namespace App\Support;

/**
 * Kombikurse bündeln die Videokapitel mehrerer Teilkurse unter einer
 * gemeinsamen Kurs-ID (siehe ComboVideoCourseSeeder). Diese Zuordnung von
 * Kapitelnamen zu Teilkurs-Label wird sowohl für die Fortschrittskarten auf
 * der Kursdetailseite (CourseController) als auch für den Videoplayer
 * (VideoCourseController) gebraucht, daher zentral an einer Stelle.
 * 'motif' wählt die Hintergrundgrafik der Teilkurs-Karte.
 */
class ComboVideoGroups
{
    public const GROUPS = [
        'SBF-SEE-BIN' => [
            ['label' => 'SBF See', 'motif' => 'compass', 'chapters' => [
                'Einführung in den Sportbootführerschein', 'Seemannschaft',
                'Praxisvideos (Motor)', 'Navigation', 'Wetterkunde', 'Ausweichregeln', 'Lichter und Signale',
            ]],
            ['label' => 'SBF Binnen', 'motif' => 'anchor', 'chapters' => [
                'Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik',
            ]],
        ],
        // Kapitel aus FunkVideoCourseSeeder.
        'SRC-UBI' => [
            ['label' => 'SRC', 'motif' => 'antenna', 'chapters' => [
                'GMDSS und Seefunkdienst', 'Funkeinrichtungen an Bord', 'UKW-Sprechfunk', 'DSC – Digitaler Selektivruf',
                'Betriebsverfahren im Seefunk', 'Seenotfunk: SAR, EPIRB und SART', 'NAVTEX',
            ]],
            ['label' => 'UBI', 'motif' => 'radio', 'chapters' => [
                'Binnenschifffahrtsfunk', 'Funkeinrichtungen und Schiffsfunkstellen', 'Verkehrskreise', 'Sprechfunk',
                'Betriebsverfahren und Rangfolgen',
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
