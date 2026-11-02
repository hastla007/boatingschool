<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\User;

/**
 * Gerätesimulation Funk, Stufe 1: Lektionen und Übungsschiff aus
 * database/data/geraetesimulation.php. Der Browser bekommt die Lektionen ohne
 * die Prüfpunkte für Gesprochenes; die prüft der Server (evaluate) mit den
 * Regeln der Praxis-Prüfungssimulation (FunkPraxis).
 */
class Geraetesimulation
{
    public const DEVICE = 'ic-m505';

    public const DEVICE_NAME = 'Icom IC-M505';

    /** @return array{ship: array, address_book: list<array>, lessons: list<array>} */
    public static function data(): array
    {
        static $data = null;

        return $data ??= include database_path('data/geraetesimulation.php');
    }

    /** Sichtbar für diesen Nutzer und Kurs? Stufe 1 ist Seefunk (SRC). */
    public static function availableFor(?User $user, CourseDefinition $course): bool
    {
        if (! in_array('SRC', FunkTraining::catalogsFor($course), true)) {
            return false;
        }

        return match (config('funk.geraetesimulation')) {
            'all' => true,
            'superadmin' => (bool) $user?->is_superadmin,
            default => false,
        };
    }

    public static function lesson(string $key): ?array
    {
        foreach (self::data()['lessons'] as $lesson) {
            if ($lesson['key'] === $key) {
                return $lesson;
            }
        }

        return null;
    }

    /** Lektionen für den Browser: Gesprochenes nur als Kennzeichen, ohne Prüfpunkte. */
    public static function lessonsForBrowser(): array
    {
        return array_map(function (array $lesson) {
            $lesson['steps'] = array_map(function (array $step) {
                $step['speech'] = isset($step['speech']);

                return $step;
            }, $lesson['steps']);

            return $lesson;
        }, self::data()['lessons']);
    }

    /**
     * Prüft einen Funkspruch gegen die Prüfpunkte des Lektionsschritts.
     *
     * @return array{checks: list<array{label: string, ok: bool}>, passed: bool}|null
     */
    public static function evaluate(string $lessonKey, int $stepIndex, string $text): ?array
    {
        $speech = self::lesson($lessonKey)['steps'][$stepIndex]['speech'] ?? null;

        if (! $speech) {
            return null;
        }

        $checks = $speech['checks'] ?? null;

        if (isset($speech['scenario'])) {
            $task = FunkPraxis::scenario('SRC', $speech['scenario']);
            $message = collect($task['fields'])->firstWhere('name', 'message');
            $checks = $message['checks'];
        }

        return FunkPraxis::checkText($checks, $text);
    }

    public static function progressKey(string $lessonKey): string
    {
        return self::DEVICE.':'.$lessonKey;
    }
}
