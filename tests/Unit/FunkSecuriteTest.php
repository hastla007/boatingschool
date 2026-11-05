<?php

namespace Tests\Unit;

use App\Support\FunkPraxis;
use App\Support\Geraetesimulation;
use Tests\TestCase;

/**
 * SECURITE an alle Funkstellen (SRC): auf Kanal 16 nur ankündigen, die
 * Meldung selbst auf dem angekündigten Arbeitskanal -- in der
 * Gerätesimulation (Lektion „Sicherheitsmeldung“) und im Praxistrainer.
 */
class FunkSecuriteTest extends TestCase
{
    public function test_the_lesson_announces_on_16_switches_to_06_and_sends_the_message_there(): void
    {
        $lesson = Geraetesimulation::lesson('sicherheit');
        $goals = array_column($lesson['steps'], 'goal');
        $this->assertSame(['dsc_sent', 'spoken', 'channel', 'spoken'], array_column($goals, 'type'));
        $this->assertSame(16, $goals[1]['channel']);
        $this->assertSame(6, $goals[2]['channel']);
        $this->assertSame(6, $goals[3]['channel']);

        [$announcement, $message] = Geraetesimulation::solutions('sicherheit');
        $this->assertStringContainsString('LISTEN ON CHANNEL ZERO SIX', $announcement);
        $this->assertStringNotContainsString('CONTAINER', $announcement);
        $this->assertStringContainsString('DRIFTING CONTAINER', $message);

        $this->assertTrue(Geraetesimulation::evaluate('sicherheit', 1, $announcement)['passed']);
        $this->assertTrue(Geraetesimulation::evaluate('sicherheit', 3, $message)['passed']);
        $this->assertTrue(Geraetesimulation::evaluate('sicherheit', 1, 'Securite Securite Securite all stations all stations all stations this is Kormoran Kormoran Kormoran DH4721 listen on channel 06 out')['passed']);

        // Die ganze Meldung auf 16 ohne Arbeitskanal zählt nicht als Ankündigung.
        $this->assertFalse(Geraetesimulation::evaluate('sicherheit', 1, $message)['passed']);
        // Ein anderer Kanal als der angekündigte 06 auch nicht.
        $this->assertFalse(Geraetesimulation::evaluate('sicherheit', 1, str_replace('ZERO SIX', 'SIX SEVEN', $announcement))['passed']);
    }

    public function test_the_praxistrainer_expects_the_announcement_and_a_working_channel(): void
    {
        $task = collect(FunkPraxis::tasks('SRC'))->firstWhere('kind', 'safety');
        $fields = collect($task['fields'])->keyBy('name');
        $this->assertStringContainsString('Kanal 16 nur für die Ankündigung', $fields['radio']['solution']);
        $this->assertStringContainsString('LISTEN ON CHANNEL', $fields['message']['solution']);

        $answers = fn (string $radio, string $message) => FunkPraxis::evaluate($task, ['radio' => $radio, 'message' => $message])['fields'];
        $ok = fn (array $results, string $label) => collect($results)->first(fn ($r) => str_starts_with($r['label'], $label))['ok'];

        $model = $answers($fields['radio']['solution'], $fields['message']['solution']);
        $this->assertTrue(collect($model['radio'])->every(fn ($r) => $r['ok']));
        $this->assertTrue(collect($model['message'])->every(fn ($r) => $r['ok']));

        // Nur Kanal 16, ohne Arbeitskanal: der Kanalwechsel fehlt.
        $only16 = $answers('Kanal 16, 25 W', str_replace(['LISTEN ON CHANNEL ZERO SIX', '– Wechsel auf Kanal 06 –'], '', $fields['message']['solution']));
        $this->assertFalse($ok($only16['radio'], 'Arbeitskanal'));
        $this->assertFalse($ok($only16['message'], 'Arbeitskanal angekündigt'));
        // Ein anderer üblicher Arbeitskanal (67) gilt im Praxistrainer ebenfalls.
        $ch67 = $answers('Kanal 16, dann Kanal 67', str_replace('ZERO SIX', 'SIX SEVEN', $fields['message']['solution']));
        $this->assertTrue($ok($ch67['radio'], 'Arbeitskanal'));
        $this->assertTrue($ok($ch67['message'], 'Arbeitskanal angekündigt'));
    }
}
