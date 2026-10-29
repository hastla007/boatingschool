<?php

namespace App\Support;

/**
 * Kombikurse spiegeln die Prüfungsbögen mehrerer Teilkurse unter einer
 * gemeinsamen Kurs-ID (siehe ComboExamPaperSeeder). Da exam_paper je Kurs
 * eindeutige paper_number braucht, bekommt der zweite Teilkurs einen
 * Nummernblock mit Offset (siehe GROUPS); diese Zuordnung von
 * paper_number zu Teilkurs-Label und Anzeigenummer wird für die
 * Prüfungsbögen-Übersicht gebraucht, analog zu ComboVideoGroups.
 */
class ComboExamGroups
{
    public const GROUPS = [
        'SBF-SEE-BIN' => [
            ['label' => 'SBF See', 'min' => 1, 'max' => 15, 'offset' => 0],
            ['label' => 'SBF Binnen', 'min' => 101, 'max' => 115, 'offset' => 100],
        ],
        'SRC-UBI' => [
            ['label' => 'SRC', 'min' => 1, 'max' => 12, 'offset' => 0],
            ['label' => 'UBI', 'min' => 101, 'max' => 106, 'offset' => 100],
        ],
    ];

    /** Gruppeninfo (label, Anzeigenummer) für einen gegebenen Prüfungsbogen, oder null außerhalb eines Kombikurses. */
    public static function infoFor(string $courseCode, int $paperNumber): ?array
    {
        foreach (self::GROUPS[$courseCode] ?? [] as $group) {
            if ($paperNumber >= $group['min'] && $paperNumber <= $group['max']) {
                return ['label' => $group['label'], 'displayNumber' => $paperNumber - $group['offset']];
            }
        }

        return null;
    }
}
