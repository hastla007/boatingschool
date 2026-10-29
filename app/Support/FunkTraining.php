<?php

namespace App\Support;

use App\Models\CourseDefinition;

/**
 * Funk-Training (Praxis-Prüfungssimulation und mündliche Prüfung) für die
 * Funkscheine: welche Teile (SRC/UBI) ein Kurs zeigt, plus Vokabeln und
 * Seefunktexte aus database/data. Die Praxisaufgaben liefert FunkPraxis.
 */
class FunkTraining
{
    /** Kurs-Code => Teile, als Reiter in dieser Reihenfolge. */
    public const COURSES = [
        'SRC' => ['SRC'],
        'UBI' => ['UBI'],
        'ADD-UBI-TO-SRC' => ['UBI'],
        'SRC-UBI' => ['SRC', 'UBI'],
    ];

    /** @return list<string> */
    public static function catalogsFor(CourseDefinition $course): array
    {
        return self::COURSES[$course->code] ?? [];
    }

    /** Gewählter Teil aus der Query (?teil=UBI), sonst der erste des Kurses; null, wenn der Kurs kein Funkkurs ist. */
    public static function selectedCatalog(CourseDefinition $course, ?string $requested): ?string
    {
        $catalogs = self::catalogsFor($course);

        return in_array($requested, $catalogs, true) ? $requested : ($catalogs[0] ?? null);
    }

    /**
     * Vokabeln eines Teils, nach Thema gruppiert.
     *
     * @return array<string, list<array{key: string, de: string, en: string}>>
     */
    public static function vocabulary(string $catalog): array
    {
        static $cache = [];

        return $cache[$catalog] ??= collect((include database_path('data/funk_vokabeln.php'))[$catalog] ?? [])
            ->map(fn (array $entries) => array_map(fn (array $entry) => [
                'key' => sprintf('VOC-%s-%s', $catalog, substr(md5($entry[0].'|'.$entry[1]), 0, 12)),
                'de' => $entry[0],
                'en' => $entry[1],
            ], $entries))
            ->all();
    }

    /** @return array<int, array{key: string, number: int, de: string, en: string}> */
    public static function texts(string $catalog): array
    {
        static $cache = [];

        if (! isset($cache[$catalog])) {
            $cache[$catalog] = [];
            foreach (array_values((include database_path('data/funk_seefunktexte.php'))[$catalog] ?? []) as $index => $text) {
                $number = $index + 1;
                $cache[$catalog][$number] = ['key' => sprintf('TXT-%s-%02d', $catalog, $number), 'number' => $number] + $text;
            }
        }

        return $cache[$catalog];
    }

    /**
     * Anteil der Wörter des Originals, die in der Eingabe vorkommen (ohne
     * Groß-/Kleinschreibung und Satzzeichen) -- eine grobe, ehrliche
     * Rückmeldung für Mitschrift und Übersetzung, keine Bewertung wie in der
     * Prüfung.
     *
     * @return array{percent: int, missing: list<string>}
     */
    public static function wordMatch(string $original, string $input): array
    {
        $words = fn (string $text) => array_values(array_filter(explode(' ', trim(FunkPraxis::normalize($text)))));
        $expected = $words($original);
        $given = array_count_values($words($input));
        $hits = 0;
        $missing = [];

        foreach ($expected as $word) {
            if (($given[$word] ?? 0) > 0) {
                $given[$word]--;
                $hits++;
            } else {
                $missing[] = $word;
            }
        }

        return [
            'percent' => $expected ? (int) round($hits / count($expected) * 100) : 0,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
