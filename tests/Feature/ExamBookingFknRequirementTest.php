<?php

namespace Tests\Feature;

use App\Support\ExamBooking;
use Tests\TestCase;

/**
 * Bug-Review (FKN-Seite widersprüchlich): Einleitung und Antrag nannten die
 * FKN-Prüfung "oft zusammen mit der Prüfung zum Sportbootführerschein",
 * FAQ und Unterlagen verlangten dagegen einen bereits vorhandenen SBF.
 * Voraussetzung ist ein SBF (See oder Binnen) ODER die gleichzeitig
 * abgelegte SBF-Prüfung -- FAQ und Unterlagen müssen das ebenso sagen.
 */
class ExamBookingFknRequirementTest extends TestCase
{
    public function test_fkn_page_consistently_allows_taking_the_sbf_exam_at_the_same_time(): void
    {
        $page = ExamBooking::page('FKN');

        $this->assertStringContainsString('zusammen mit der Prüfung zum Sportbootführerschein', $page['intro']);

        $faq = $page['faq']['Brauche ich dafür einen Bootsführerschein?'];
        $this->assertStringContainsString('See oder Binnen', $faq);
        $this->assertStringContainsString('SBF-Prüfung gleichzeitig', $faq);

        $licenceDocument = collect($page['documents'])->first(fn (string $d) => str_contains($d, 'Sportbootführerschein'));
        $this->assertNotNull($licenceDocument);
        $this->assertStringContainsString('gleichzeitig', $licenceDocument);
    }
}
