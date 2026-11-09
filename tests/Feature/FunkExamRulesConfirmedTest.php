<?php

namespace Tests\Feature;

use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Vom Auftraggeber bestätigte Prüfungsregeln (Funk): Fragen je Bogen,
 * Mindestzahl richtiger Antworten und Zeit. Jede Frage zählt richtig oder
 * falsch (keine Teilpunkte).
 */
class FunkExamRulesConfirmedTest extends TestCase
{
    use InteractsWithTenants;

    public function test_src_ubi_and_ubi_supplement_rules_match_the_confirmed_values(): void
    {
        $expected = [
            'SRC' => ['questions' => 24, 'min_correct' => 19, 'minutes' => 30],
            'UBI' => ['questions' => 22, 'min_correct' => 17, 'minutes' => 60],
            'ADD-UBI-TO-SRC' => ['questions' => 10, 'min_correct' => 8, 'minutes' => 30],
        ];

        foreach ($expected as $code => $rule) {
            $course = $this->existingCourse($code);
            [$ruleSet, $sizes] = $this->onAdmin(fn () => [
                $course->activeExamRuleSet(),
                ExamPaperQuestion::whereIn('exam_paper_id', $course->examPapers()->pluck('id'))
                    ->selectRaw('exam_paper_id, count(*) as n')->groupBy('exam_paper_id')->pluck('n')->unique()->values()->all(),
            ]);

            $this->assertInstanceOf(ExamRuleSet::class, $ruleSet, "{$code}: freigegebenes Regelwerk");
            $this->assertTrue($ruleSet->isVerified(), "{$code}: Regelwerk freigegeben");
            $this->assertSame([$rule['questions']], $sizes, "{$code}: Fragen je Bogen");
            $this->assertSame($rule['minutes'] * 60, (int) $ruleSet->time_limit_seconds, "{$code}: Zeit");
            $this->assertSame([], $ruleSet->passing_rule['parts'] ?? [], "{$code}: keine Teilgrenzen");
            $ratio = (float) $ruleSet->passing_rule['min_correct_ratio'];
            $this->assertSame($rule['min_correct'], (int) ceil($ratio * $rule['questions'] - 1e-9), "{$code}: Mindestzahl richtig");
            $this->assertStringContainsString("Bestanden ab {$rule['min_correct']} von {$rule['questions']}", $ruleSet->passingSummary($rule['questions']));
        }
    }
}
