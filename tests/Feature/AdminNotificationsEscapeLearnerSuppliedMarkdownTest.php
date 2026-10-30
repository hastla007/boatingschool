<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Notifications\PraxisExamAppointmentResponded;
use App\Notifications\PraxisFormSubmissionReceived;
use App\Notifications\WillkommensboxOrderPlaced;
use Tests\TestCase;

/**
 * Bug-Review (Markdown-Injection über Name/Lieferadresse in Admin-Mails):
 * f54205f escapte nur response_comment -- der frei wählbare Name des
 * Lerners und die Lieferadresse der Willkommensbox landeten weiterhin
 * ungeschützt in Markdown-Zeilen der an die Bootsschule verschickten
 * Mails und erschienen dort als klickbarer Link.
 */
class AdminNotificationsEscapeLearnerSuppliedMarkdownTest extends TestCase
{
    private const LINK = '[Jetzt Konto bestätigen](https://phishing.example/login)';

    private function learner(): User
    {
        $user = new User(['display_name' => self::LINK]);
        $user->id = '00000000-0000-0000-0000-0000000000aa';

        return $user;
    }

    private function admin(): User
    {
        return new User(['display_name' => 'Admin', 'email' => 'admin@example.test']);
    }

    private function assertNoInjectedLink(string $html): void
    {
        $this->assertStringNotContainsString('href="https://phishing.example/login"', $html);
        $this->assertStringContainsString('Jetzt Konto bestätigen', $html);
    }

    public function test_the_learner_name_is_escaped_in_the_appointment_response_mail(): void
    {
        $appointment = new PraxisExamAppointment([
            'label' => 'Prüfungstermin',
            'response' => 'accepted',
            'exam_date' => now()->addWeek(),
        ]);
        $appointment->setRelation('submission', (object) ['user' => $this->learner(), 'user_id' => $this->learner()->id]);

        $this->assertNoInjectedLink((new PraxisExamAppointmentResponded($appointment))->toMail($this->admin())->render());
    }

    public function test_the_learner_name_is_escaped_in_the_praxis_submission_mail(): void
    {
        $submission = new PraxisFormSubmission;
        $submission->user_id = $this->learner()->id;
        $submission->setRelation('user', $this->learner());
        $submission->setRelation('course', new CourseDefinition(['name' => 'SRC']));

        $this->assertNoInjectedLink((new PraxisFormSubmissionReceived($submission))->toMail($this->admin())->render());
    }

    public function test_the_learner_name_and_shipping_address_are_escaped_in_the_willkommensbox_mail(): void
    {
        $order = new WillkommensboxOrder([
            'street' => '[Lieferung bestätigen](https://phishing.example/track)',
            'postal_code' => '12345',
            'city' => 'Teststadt',
            'country' => 'Deutschland',
        ]);
        $order->user_id = $this->learner()->id;
        $order->setRelation('user', $this->learner());

        $html = (new WillkommensboxOrderPlaced($order))->toMail($this->admin())->render();

        $this->assertNoInjectedLink($html);
        $this->assertStringNotContainsString('href="https://phishing.example/track"', $html);
        $this->assertStringContainsString('Lieferung bestätigen', $html);
        $this->assertStringContainsString('12345 Teststadt', $html);
    }
}
