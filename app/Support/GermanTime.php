<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Bug-Review B30: Uhrzeiten wurden in UTC statt deutscher Zeit angezeigt.
 * APP_TIMEZONE bleibt bewusst UTC (config/app.php) -- würde die
 * Standard-Zeitzone stattdessen auf Europe/Berlin stehen, würde Eloquent
 * beim Speichern eines 'datetime'-Attributs den Wert ohne Offset
 * serialisieren; die Postgres-Verbindung (deren Session-Zeitzone UTC ist)
 * würde denselben Zeitstempel dann 1-2 Stunden falsch interpretieren --
 * genau das hat die laufzeitabhängigen Prüfungs-Zeitlimit-Berechnungen
 * (ExamController::evaluate()/timeExpired()) durcheinandergebracht, als das
 * ausprobiert wurde. Die Umrechnung auf deutsche Zeit passiert deshalb
 * ausschließlich hier, an der Anzeige-Stelle, nie an der gespeicherten
 * oder für Berechnungen genutzten Uhrzeit selbst.
 */
class GermanTime
{
    public static function format(?CarbonInterface $value, string $format): ?string
    {
        return $value?->copy()->setTimezone('Europe/Berlin')->format($format);
    }
}
