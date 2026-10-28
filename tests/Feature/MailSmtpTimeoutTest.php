<?php

namespace Tests\Feature;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * Bug-Review Betrieb: Illuminate\Mail\MailManager::configureSmtpTransport()
 * ruft $stream->setTimeout(...) nur auf, wenn isset($config['timeout'])
 * true ist -- isset() auf einem vorhandenen, aber NULL-wertigen
 * Array-Eintrag liefert in PHP immer false, der bisherige Wert
 * 'timeout' => null in config/mail.php wirkte also nie. Ohne gesetztes
 * Timeout fällt Symfony Mailers SocketStream auf PHPs
 * default_socket_timeout zurück (i. d. R. 60 Sekunden) -- ist der
 * Mailserver nicht erreichbar, hängt dadurch jeder Request mit
 * Mailversand für die volle Dauer.
 */
class MailSmtpTimeoutTest extends TestCase
{
    public function test_the_smtp_mailer_config_has_an_explicit_timeout(): void
    {
        $timeout = config('mail.mailers.smtp.timeout');

        $this->assertNotNull($timeout);
        $this->assertIsNumeric($timeout);
        $this->assertGreaterThan(0, $timeout);
    }

    /** Baut den echten Symfony-Transport auf Basis der App-Konfiguration und prüft, dass das Timeout dort tatsächlich ankommt. */
    public function test_the_smtp_transport_is_actually_configured_with_that_timeout(): void
    {
        $config = config('mail.mailers.smtp');

        $transport = app(MailManager::class)->createSymfonyTransport($config);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame((float) $config['timeout'], $stream->getTimeout());
    }
}
