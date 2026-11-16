<?php

namespace Tests\Unit;

use App\Support\SentryScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

/**
 * Geheimnisse in Adressen gehen nicht an Sentry: Löschlink der App
 * (request_id, confirm_key), App-Anmeldung im Web (bp_app_login) und
 * Beitrittscodes bzw. Einladungen von Lerngruppen im Pfad.
 */
class SentryScrubberTest extends TestCase
{
    public function test_deletion_link_app_login_and_group_codes_are_removed(): void
    {
        $join = 'g'.str_repeat('a1', 16);
        $invite = 'i'.str_repeat('b2', 16);
        $cases = [
            'https://app.example.test/app/konto-loeschen?request_id=9b1c4f0e-1111-4222-8333-944455556666&confirm_key=GeHeIm123' => 'https://app.example.test/app/konto-loeschen?request_id=[entfernt]&confirm_key=[entfernt]',
            'https://schule.example.test/kurse?bp_app_login=abc123XYZ&x=1' => 'https://schule.example.test/kurse?bp_app_login=[entfernt]&x=1',
            "https://app.example.test/api/v1/groups/join/{$join}?tenant=s" => 'https://app.example.test/api/v1/groups/join/[entfernt]?tenant=s',
            "https://app.example.test/gruppe/{$invite}/" => 'https://app.example.test/gruppe/[entfernt]/',
            "GET /gruppe/{$join}" => 'GET /gruppe/[entfernt]',
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, SentryScrubber::text($raw));
        }
        // Lerngruppen-Verwaltung ohne Code bleibt lesbar.
        $this->assertSame('/admin/lerngruppen/42', SentryScrubber::text('/admin/lerngruppen/42'));
    }

    public function test_the_request_of_an_event_is_scrubbed(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example.test/app/konto-loeschen',
            'query_string' => 'request_id=9b1c4f0e-1111-4222-8333-944455556666&confirm_key=GeHeIm123',
        ]);

        $request = SentryScrubber::scrub($event)->getRequest();

        $this->assertSame('request_id=[entfernt]&confirm_key=[entfernt]', $request['query_string']);
    }
}
