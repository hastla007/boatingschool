<?php

namespace Tests\Feature;

use App\Support\GermanTime;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Bug-Review B30: Zeitstempel wurden in UTC statt deutscher Zeit angezeigt.
 * APP_TIMEZONE bleibt bewusst UTC (siehe App\Support\GermanTime), damit
 * Speicherung/Berechnung (z. B. ExamController-Zeitlimits) unverändert und
 * korrekt bleiben -- die Umrechnung passiert ausschließlich bei der
 * Anzeige.
 */
class GermanTimeDisplayTest extends TestCase
{
    public function test_a_summer_utc_timestamp_is_shown_two_hours_later_in_cest(): void
    {
        $utc = Carbon::parse('2026-06-05 16:00:00', 'UTC');

        $this->assertSame('05.06.2026 18:00', GermanTime::format($utc, 'd.m.Y H:i'));
    }

    public function test_a_winter_utc_timestamp_is_shown_one_hour_later_in_cet(): void
    {
        $utc = Carbon::parse('2026-01-05 16:00:00', 'UTC');

        $this->assertSame('05.01.2026 17:00', GermanTime::format($utc, 'd.m.Y H:i'));
    }

    public function test_formatting_a_null_value_returns_null(): void
    {
        $this->assertNull(GermanTime::format(null, 'd.m.Y H:i'));
    }

    public function test_it_does_not_mutate_the_original_instance(): void
    {
        $utc = Carbon::parse('2026-06-05 16:00:00', 'UTC');

        GermanTime::format($utc, 'd.m.Y H:i');

        $this->assertSame('UTC', $utc->timezoneName);
        $this->assertSame('2026-06-05 16:00:00', $utc->format('Y-m-d H:i:s'));
    }
}
