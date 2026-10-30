<?php

namespace Tests\Feature;

use App\Models\PraxisTask;
use Tests\TestCase;

/**
 * Bug-Review BB14: Die Praxistrainer-Karte zum Leuchtfeuer-Kürzel "LFl"
 * beschrieb das Blinkfeuer fachlich ungenau. Siehe die begleitende
 * Migration 2024_02_01_000492_fix_bb14_praxistrainer_lfl_blinkfeuer_wording.
 */
class BB14PraxistrainerLFlWordingTest extends TestCase
{
    public function test_sbf_see_card_states_light_phase_is_shorter_than_dark_phase(): void
    {
        $task = PraxisTask::where('content_id', 'PRAXIS-0088')->firstOrFail();

        $this->assertStringContainsString('kürzer als die Dunkelphase', $task->antwort);
        $this->assertStringContainsString('mindestens zwei Sekunden', $task->antwort);
        $this->assertStringNotContainsString('länger als zwei Sekunden, danach Dunkelphase', $task->antwort);
    }

    public function test_combo_course_mirrored_card_is_fixed_too(): void
    {
        $task = PraxisTask::where('content_id', 'PRAXIS-0088-SEE-BIN')->firstOrFail();

        $this->assertStringContainsString('kürzer als die Dunkelphase', $task->antwort);
        $this->assertStringNotContainsString('länger als zwei Sekunden, danach Dunkelphase', $task->antwort);
    }
}
