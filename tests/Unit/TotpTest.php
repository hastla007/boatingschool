<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** TOTP nach RFC 6238 (Testwerte aus Anhang B, SHA1, 8 → hier 6 Ziffern). */
class TotpTest extends TestCase
{
    public function test_codes_match_the_rfc_6238_test_vectors(): void
    {
        // Geheimnis "12345678901234567890" (ASCII); RFC-Werte mit 8 Ziffern, die letzten 6 gelten.
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $rfc) {
            $this->assertSame(substr($rfc, -6), Totp::at($secret, $time), "t={$time}");
        }
    }

    public function test_verify_allows_one_step_of_drift_and_blocks_reuse(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $now = 1_700_000_000;
        $code = Totp::at($secret, $now);

        $this->assertSame(intdiv($now, 30), Totp::verify($secret, $code, $now));
        $this->assertNotNull(Totp::verify($secret, $code, $now + 30), 'ein Schritt später');
        $this->assertNull(Totp::verify($secret, $code, $now + 90), 'drei Schritte später');
        $wrong = substr($code, 0, 5).((((int) $code[5]) + 1) % 10);
        $this->assertNull(Totp::verify($secret, $wrong, $now), 'falscher Code');
        $this->assertNull(Totp::verify($secret, $code, $now, intdiv($now, 30)), 'derselbe Code nicht zweimal');
        $this->assertNull(Totp::verify($secret, 'abc', $now));
        $this->assertStringStartsWith('otpauth://totp/Bootsschule:anna%40example.de?secret='.$secret, Totp::uri('Bootsschule', 'anna@example.de', $secret));
    }
}
