<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\Module;

/**
 * Seite "Prüfung buchen" für Teilnehmer, die sich selbst zur Prüfung
 * anmelden (ExamRegistration::SELF): je Kurs eine oder mehrere Seiten
 * (Reiter, z. B. SBF See und SBF Binnen im Kombikurs) mit den
 * Prüfungsvarianten, die Variante des Kurses zuerst. Inhalte in
 * database/data/pruefung_buchen.php.
 */
class ExamBooking
{
    /** @return array<string, mixed> */
    private static function data(): array
    {
        static $data = null;

        return $data ??= include database_path('data/pruefung_buchen.php');
    }

    /** @return array<string, string> Seite => Variante, die zuerst steht; leer, wenn der Kurs keine Seite hat. */
    public static function pagesFor(CourseDefinition $course): array
    {
        return self::data()['courses'][$course->code] ?? [];
    }

    /** Gewählte Seite aus der Query (?teil=SBF-BIN), sonst die erste des Kurses. */
    public static function selectedPage(CourseDefinition $course, ?string $requested): ?string
    {
        $pages = self::pagesFor($course);

        return array_key_exists((string) $requested, $pages) ? $requested : array_key_first($pages);
    }

    /** @return array<string, mixed> */
    public static function page(string $key): array
    {
        return self::data()['pages'][$key];
    }

    /** @return array<string, array<string, mixed>> */
    public static function pages(): array
    {
        return self::data()['pages'];
    }

    /** @return list<array<string, string>> */
    public static function providers(): array
    {
        return self::data()['providers'];
    }

    /**
     * Varianten einer Seite, die angegebene zuerst, mit Anzeigetexten für
     * Umfang und Bestehensgrenze (Katalogzahlen aus der Datenbank).
     *
     * @return list<array<string, mixed>>
     */
    public static function variants(string $page, string $first): array
    {
        $variants = self::page($page)['variants'];
        $ordered = [$first => $variants[$first]] + $variants;
        $catalogSizes = Module::whereIn('code', collect($variants)->flatMap(fn ($v) => array_column($v['parts'], 'module'))->unique()->all())
            ->withCount('questions')->pluck('questions_count', 'code');

        return collect($ordered)->map(function (array $variant, string $key) use ($catalogSizes) {
            $parts = array_map(fn (array $part) => $part + ['catalog' => $catalogSizes[$part['module']] ?? null], $variant['parts']);

            return $variant + [
                'key' => $key,
                'scope' => self::scope($parts),
                'pass' => $variant['pass'] ?? self::pass($parts),
                'minutes' => $variant['minutes'] ?? null,
            ];
        })->values()->all();
    }

    /** "24 Fragen aus 180" bzw. "30 Fragen: 7 Basisfragen aus 72, 23 See-Fragen aus 213". */
    private static function scope(array $parts): string
    {
        if (count($parts) === 1) {
            $part = $parts[0];

            return isset($part['of'])
                ? "{$part['of']} Fragen aus {$part['catalog']}"
                : "Fragen aus dem amtlichen Katalog ({$part['catalog']} Fragen)";
        }

        return array_sum(array_column($parts, 'of')).' Fragen: '
            .implode(', ', array_map(fn ($p) => "{$p['of']} {$p['label']} aus {$p['catalog']}", $parts));
    }

    /** "ab 19 richtigen (max. 5 Fehler)" bzw. "Basisfragen mind. 5 von 7 und See-Fragen mind. 18 von 23"; null ohne amtliche Grenze. */
    private static function pass(array $parts): ?string
    {
        if (collect($parts)->contains(fn ($p) => ! isset($p['min']))) {
            return null;
        }

        if (count($parts) === 1) {
            return sprintf('ab %d richtigen (max. %d Fehler)', $parts[0]['min'], $parts[0]['of'] - $parts[0]['min']);
        }

        return implode(' und ', array_map(fn ($p) => "{$p['label']} mind. {$p['min']} von {$p['of']}", $parts));
    }
}
