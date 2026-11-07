<?php

namespace Tests\Feature;

use App\Models\WillkommensboxOrder;
use App\Notifications\WillkommensboxOrderShipped;
use Tests\TestCase;

/**
 * Review (Sicherheit): von der Schule eingetragene Texte erscheinen in Mails
 * an Lernende nur als Text, nicht als anklickbarer Markdown-Link.
 */
class LearnerMailMarkdownTest extends TestCase
{
    public function test_a_tracking_number_with_markdown_link_syntax_is_escaped(): void
    {
        $order = new WillkommensboxOrder(['tracking_number' => '[Sendung verfolgen](https://phish.example)']);
        $mail = (new WillkommensboxOrderShipped($order))->toMail(null);
        $line = collect($mail->introLines)->first(fn ($l) => str_starts_with($l, 'Sendungsnummer'));

        // Escapte eckige Klammern genügen: ohne „[…]“ entsteht kein Link.
        $this->assertStringContainsString('\\[Sendung verfolgen\\](https', $line);
    }
}
