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
     * Vokabeln eines Teils, nach Thema gruppiert (UBI: Buchstabiertafel).
     *
     * @return array<string, list<array{key: string, de: string, en: string, hint?: string}>>
     */
    public static function vocabulary(string $catalog): array
    {
        static $cache = [];

        // UBI: die Buchstabiertafel als Karteikarten (Buchstabe -> Wort, Aussprache als Hinweis).
        if ($catalog === 'UBI') {
            return $cache[$catalog] ??= ['Buchstabiertafel' => array_map(fn (string $letter, array $entry) => [
                'key' => sprintf('VOC-UBI-%s', $letter),
                'de' => $letter,
                'en' => $entry[0],
                'hint' => $entry[1],
            ], array_keys(self::ALPHABET), self::ALPHABET)];
        }

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
     * Seite "Prüfung buchen" eines Teils: Anmeldung, Gebühr, Prüfungsteile, häufige Fragen.
     *
     * @return array<string, mixed>
     */
    public static function booking(string $catalog): array
    {
        static $data = null;

        return ($data ??= include database_path('data/funk_pruefung.php'))[$catalog];
    }

    /** Internationale Buchstabiertafel mit Aussprache (ITU). */
    public const ALPHABET = [
        'A' => ['Alfa', 'AL-FA'], 'B' => ['Bravo', 'BRA-WO'], 'C' => ['Charlie', 'TSCHAR-LI'], 'D' => ['Delta', 'DEL-TA'],
        'E' => ['Echo', 'ECK-O'], 'F' => ['Foxtrot', 'FOX-TROTT'], 'G' => ['Golf', 'GOLF'], 'H' => ['Hotel', 'HO-TELL'],
        'I' => ['India', 'IN-DI-A'], 'J' => ['Juliett', 'DSCHU-LI-ETT'], 'K' => ['Kilo', 'KI-LO'], 'L' => ['Lima', 'LI-MA'],
        'M' => ['Mike', 'MEIK'], 'N' => ['November', 'NO-WEMM-BER'], 'O' => ['Oscar', 'OSS-KAR'], 'P' => ['Papa', 'PA-PA'],
        'Q' => ['Quebec', 'KI-BECK'], 'R' => ['Romeo', 'RO-MI-O'], 'S' => ['Sierra', 'SI-ER-RA'], 'T' => ['Tango', 'TANG-GO'],
        'U' => ['Uniform', 'JU-NI-FORM'], 'V' => ['Victor', 'WICK-TAR'], 'W' => ['Whiskey', 'WISS-KI'], 'X' => ['X-ray', 'ECKS-REH'],
        'Y' => ['Yankee', 'JENG-KI'], 'Z' => ['Zulu', 'SU-LU'],
    ];

    /** Ziffern im deutschen Sprechfunk ("zwo" statt "zwei", um Verwechslung mit "drei" zu vermeiden). */
    public const DIGITS = ['Null', 'Eins', 'Zwo', 'Drei', 'Vier', 'Fünf', 'Sechs', 'Sieben', 'Acht', 'Neun'];

    /**
     * Übungstexte der mündlichen UBI-Prüfung (Aufnahme bzw. Abgabe).
     *
     * @return array<int, array{key: string, number: int, text?: string, lines?: list<string>}>
     */
    public static function ubiTexts(string $modul): array
    {
        static $cache = [];

        if (! isset($cache[$modul])) {
            $prefix = $modul === 'abgabe' ? 'ABG' : 'AUF';
            $cache[$modul] = [];
            foreach (array_values((include database_path('data/funk_ubi_texte.php'))[$modul] ?? []) as $index => $item) {
                $number = $index + 1;
                $cache[$modul][$number] = ['key' => sprintf('UBI-%s-%02d', $prefix, $number), 'number' => $number]
                    + (is_array($item) ? ['lines' => $item] : ['text' => $item]);
            }
        }

        return $cache[$modul];
    }

    /** Ein Wort buchstabiert: Buchstaben mit der Buchstabiertafel, Ziffern einzeln, z. B. "Kilo Echo Lima" bzw. "Sieben Eins Zwo". */
    public static function spellGerman(string $word): string
    {
        $map = ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS'];
        $chars = str_split(strtr(mb_strtoupper($word), $map));

        return implode(' ', array_filter(array_map(fn ($c) => ctype_digit($c) ? self::DIGITS[(int) $c] : (self::ALPHABET[$c][0] ?? null), $chars)));
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
