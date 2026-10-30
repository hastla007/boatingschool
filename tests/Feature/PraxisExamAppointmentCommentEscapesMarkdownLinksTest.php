<?php

namespace Tests\Feature;

use App\Models\PraxisExamAppointment;
use App\Notifications\PraxisExamAppointmentResponded;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Phishing-Link via Prüfungstermin-Kommentar in Admin-E-Mail
 * einschleusbar): MailMessage::line() rendert über Laravels Markdown-
 * Mailvorlage -- ein im Kommentarfeld eingeschleuster echter Markdown-Link
 * ("[Text](https://...)") wurde darin bisher als klickbarer <a>-Link
 * dargestellt.
 */
class PraxisExamAppointmentCommentEscapesMarkdownLinksTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_markdown_link_in_the_comment_is_not_rendered_as_a_clickable_link(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $appointment = new PraxisExamAppointment([
            'tenant_id' => $tenant->id,
            'label' => 'Prüfungstermin',
            'response' => 'accepted',
            'response_comment' => '[Klicken Sie hier für Ihre Unterlagen](https://phishing.example/login)',
            'exam_date' => now()->addWeek(),
        ]);
        $appointment->setRelation('submission', (object) [
            'user' => $learner,
            'user_id' => $learner->id,
        ]);

        $html = (new PraxisExamAppointmentResponded($appointment))->toMail($admin)->render();

        $this->assertStringNotContainsString('href="https://phishing.example/login"', $html);
        $this->assertStringContainsString('phishing.example/login', $html);
        $this->assertStringContainsString('Klicken Sie hier', $html);
    }
}
