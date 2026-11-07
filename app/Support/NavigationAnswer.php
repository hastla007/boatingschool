<?php

namespace App\Support;

/**
 * Eigene Antwort bei Navigationsaufgaben prüfen.
 *
 * Besteht die Musterlösung nur aus Messwerten (Kurse/Peilungen in Grad,
 * Positionen, Distanzen in sm, Fahrt in kn, Uhrzeiten, Minuten) plus
 * Beschriftungen wie „rwK =“ oder Tonnennamen, wird automatisch mit
 * Toleranz verglichen. Alles andere (Beschreibungen, „Siehe Karte“)
 * vergleicht der Lernende selbst mit der Musterlösung.
 */
class NavigationAnswer
{
    /** Toleranzen je Messwert. */
    public const TOLERANCE = ['angle' => 2.0, 'position' => 0.3, 'distance' => 0.2, 'speed' => 0.3, 'time' => 3.0, 'minutes' => 3.0];

    /** Beschriftungen von Navigationswerten (Kurse, Peilungen, Missweisung, Ablenkung, Fahrt). */
    private const NAV_LABELS = ['rwk', 'mgk', 'kdw', 'küg', 'kug', 'kük', 'kuk', 'bv', 'füg', 'fug', 'fdw', 'rwp', 'mgp', 'mw', 'abl', 'dev', 'kak', 'mak'];

    /** Wörter, die neben Messwerten in automatisch prüfbaren Lösungen vorkommen dürfen. */
    private const LABELS = ['rwk', 'mgk', 'rwp', 'mgp', 'kdw', 'kük', 'kuk', 'küg', 'kug', 'bv', 'füg', 'fug', 'fdw', 'uhr', 'gegen', 'um', 'ca', 'tonne', 'leuchttonne', 'n', 's', 'e', 'w', 'o'];

    /**
     * @return array{auto: bool, correct: ?bool, expected: list<array{type: string, value: float|array}>}
     */
    public static function evaluate(string $model, string $given): array
    {
        $expected = self::measurements($model);
        if ($expected === [] || ! self::onlyLabelsLeft($model)) {
            return ['auto' => false, 'correct' => null, 'expected' => $expected];
        }

        $actual = self::measurements(self::withoutExtras($model, $given), lenient: true);

        return ['auto' => true, 'correct' => self::matches($expected, $actual), 'expected' => $expected];
    }

    /** @return list<array{type: string, value: float|array}> */
    public static function measurements(string $text, bool $lenient = false): array
    {
        $text = str_replace(['’', '′', '´', '`'], "'", $text);
        $found = [];
        $mask = function (array $m) use (&$text) {
            $text = substr_replace($text, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
        };

        // Positionen: 54° 03,5' N 008° 06,9' E oder N 54° 03,5' E 008° 06,9'
        $deg = "(\d{1,2})(?:\s*°\s*|\s+)(\d{1,2}(?:[.,]\d+)?)\s*'?";
        $lonDeg = "(\d{1,3})(?:\s*°\s*|\s+)(\d{1,2}(?:[.,]\d+)?)\s*'?";
        $positionPatterns = [
            'suffix' => "/{$deg}\s*([NS])\W{0,3}{$lonDeg}\s*([EWO])/iu",
            'prefix' => "/\b([NS])\s*{$deg}\W{0,3}([EWO])\s*{$lonDeg}/iu",
        ];
        foreach ($positionPatterns as $style => $pattern) {
            if (! preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($all as $m) {
                [$latD, $latM, $ns, $lonD, $lonM, $ew] = $style === 'suffix'
                    ? [$m[1][0], $m[2][0], $m[3][0], $m[4][0], $m[5][0], $m[6][0]]
                    : [$m[2][0], $m[3][0], $m[1][0], $m[5][0], $m[6][0], $m[4][0]];
                $lat = ((int) $latD * 60 + self::num($latM)) * (strtoupper($ns) === 'S' ? -1 : 1);
                $lon = ((int) $lonD * 60 + self::num($lonM)) * (strtoupper($ew) === 'W' ? -1 : 1);
                $found[] = ['pos' => $m[0][1], 'type' => 'position', 'value' => [$lat, $lon]];
                $mask($m);
            }
        }
        // Uhrzeit: „10:42“ immer, „10.42“ nur mit „Uhr“ -- sonst wäre eine
        // Dezimalzahl wie „0.80 sm“ oder „5.25 kn“ eine Uhrzeit.
        $patterns = [
            ['time', '/\b(\d{1,2}):(\d{2})(?!\d)(?:\s*uhr\b)?/iu'],
            ['time', '/\b(\d{1,2})\.(\d{2})\s*uhr\b/iu'],
            ['angle', '/(\d{1,3}(?:[.,]\d+)?)\s*°/u'],
            ['distance', '/(\d+(?:[.,]\d+)?)\s*sm\b/iu'],
            ['speed', '/(\d+(?:[.,]\d+)?)\s*(?:kn|knoten)\b/iu'],
            ['minutes', '/(\d+(?:[.,]\d+)?)\s*min\b\.?/iu'],
        ];
        foreach ($patterns as [$type, $pattern]) {
            if ($type === 'time' && ! $lenient && ! preg_match('/uhr|\d{1,2}:\d{2}/iu', $text)) {
                continue;
            }
            if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    $value = $type === 'time' ? (int) $m[1][0] * 60 + (int) $m[2][0] : self::num($m[1][0]);
                    $found[] = ['pos' => $m[0][1], 'type' => $type, 'value' => $value];
                    $mask($m);
                }
            }
        }
        // Eigene Antwort ohne Einheit („074“, „6,1“): als Zahl ohne Typ.
        if ($lenient && preg_match_all('/(?<![\w,.])(\d+(?:[.,]\d+)?)(?![\w,.])/u', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                // „14.30“ ohne „Uhr“ kann auch eine Uhrzeit sein (Minuten getrennt merken).
                $clock = preg_match('/^(\d{1,2})[.,](\d{2})$/', $m[1][0], $c) && (int) $c[2] < 60 ? (int) $c[1] * 60 + (int) $c[2] : null;
                $found[] = ['pos' => $m[0][1], 'type' => 'number', 'value' => self::num($m[1][0]), 'clock' => $clock];
            }
        }
        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return array_map(fn ($f) => array_filter(['type' => $f['type'], 'value' => $f['value'], 'clock' => $f['clock'] ?? null], fn ($v) => $v !== null), $found);
    }

    /**
     * Zusätze in der eigenen Antwort ausblenden, die keine Messwerte der
     * Lösung sind: Klammern („038° (MgK 035°)“), Tonnennamen aus der
     * Musterlösung auch mit Leerzeichen („TG 15“, „A 2“, „4 a“), Namen mit
     * Ziffern („TG15“) und Nummerierungen („Kurs 1: …“, „1) …“).
     */
    private static function withoutExtras(string $model, string $given): string
    {
        $text = preg_replace('/\([^)]*\)/u', ' ', $given);
        preg_match_all('/"([^"]*)"|„([^“]*)“/u', $model, $quoted, PREG_SET_ORDER);
        foreach ($quoted as $q) {
            foreach (preg_split('/[\/,]/u', $q[2] ?? $q[1] ?: ($q[1] ?? '')) as $name) {
                $parts = preg_split('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})|\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
                if ($parts !== [] && preg_match('/\d/u', $name)) {
                    $pattern = implode('[\s\-]*', array_map(fn ($part) => preg_quote($part, '/'), $parts));
                    $text = preg_replace('/(?<![\p{L}\d])'.$pattern.'(?![\p{L}\d])/iu', ' ', $text);
                }
            }
        }
        $text = preg_replace('/(?<![\p{L}\d])\p{L}+\d+\p{L}?(?![\p{L}\d])/u', ' ', $text);
        // Zwischenwerte mit anderer Beschriftung als in der Lösung („rwK 231°,
        // Mw -2°, MgK 229°“ bei gefragtem MgK) zählen nicht mit.
        $modelLower = mb_strtolower($model);
        foreach (self::NAV_LABELS as $label) {
            if (! preg_match('/(?<!\p{L})'.preg_quote($label, '/').'(?!\p{L})/u', $modelLower)) {
                $text = preg_replace('/(?<!\p{L})'.preg_quote($label, '/').'\s*[=:]?\s*[-+]?\d+(?:[.,]\d+)?\s*(?:°|sm\b|kn\b)?(?:\s*[EWO](?!\p{L}))?/iu', ' ', $text);
            }
        }

        return preg_replace('/(?<![\d,.:])\d{1,2}[:)](?=\s)/u', ' ', $text);
    }

    private static function onlyLabelsLeft(string $model): bool
    {
        $rest = preg_replace('/"[^"]*"|„[^“]*“/u', ' ', $model);
        $rest = preg_replace("/\d+(?:[.,]\d+)?\s*(?:°|'|sm\b|kn\b|knoten\b|min\b\.?|uhr\b)?|\d{1,2}[:.]\d{2}/iu", ' ', $rest);
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($rest), -1, PREG_SPLIT_NO_EMPTY);

        return collect($words)->every(fn ($w) => in_array($w, self::LABELS, true));
    }

    /**
     * Jeder erwartete Messwert braucht in derselben Reihenfolge einen
     * passenden Wert in der Antwort (gleicher Typ oder Zahl ohne Einheit).
     */
    private static function matches(array $expected, array $actual): bool
    {
        // Genau so viele Werte wie erwartet: „074/076“ oder eine Zahlenreihe
        // darf nicht durch Ausprobieren mehrerer Werte richtig werden.
        if (count($actual) !== count($expected)) {
            return false;
        }
        $i = 0;
        foreach ($expected as $e) {
            $hit = false;
            for (; $i < count($actual); $i++) {
                $a = $actual[$i];
                $value = $e['type'] === 'time' && isset($a['clock']) ? $a['clock'] : $a['value'];
                if (($a['type'] === $e['type'] || ($a['type'] === 'number' && $e['type'] !== 'position')) && self::close($e, $value)) {
                    $hit = true;
                    $i++;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private static function close(array $expected, float|int|array $value): bool
    {
        $tolerance = self::TOLERANCE[$expected['type']];
        if ($expected['type'] === 'position') {
            return is_array($value) && abs($expected['value'][0] - $value[0]) <= $tolerance && abs($expected['value'][1] - $value[1]) <= $tolerance;
        }
        if (is_array($value)) {
            return false;
        }
        if ($expected['type'] === 'angle') {
            if ($value < 0 || $value > 360) {
                return false;
            }
            $diff = fmod(abs($expected['value'] - $value), 360);

            return min($diff, 360 - $diff) <= $tolerance;
        }

        if ($expected['type'] === 'time') {
            // Über Mitternacht: 23:58 und 00:01 liegen 3 Minuten auseinander.
            $diff = fmod(abs($expected['value'] - $value), 1440);

            return min($diff, 1440 - $diff) <= $tolerance + 1e-9;
        }

        return abs($expected['value'] - $value) <= $tolerance + 1e-9;
    }

    private static function num(string $text): float
    {
        return (float) str_replace(',', '.', $text);
    }
}
