<?php

namespace Tests\Feature\Api;

use App\Models\ApiDeviceSession;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Hash;

/** Mobile-API: Härtungen aus dem Security-Audit (Konto-Sperre, Refresh-Toleranz). */
class AuthHardeningApiTest extends MobileApiTestCase
{
    public function test_foreign_failed_attempts_do_not_lock_the_app_login(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!')]);

        foreach (range(1, 25) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.{$i}.0.1"])
                ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'falsch', 'device_name' => 'x'])->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'geheim123!', 'device_name' => 'x'])->assertOk();

        // Aus einem Netz mit vielen Fehlversuchen: gesperrt, ohne zu prüfen.
        foreach (range(1, LoginThrottle::NETWORK_ATTEMPTS) as $i) {
            LoginThrottle::failed($user->email, '203.0.113.'.$i);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'geheim123!', 'device_name' => 'x'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_a_token_superseded_during_the_grace_period_revokes_the_session(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $r0 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data');

        $r1 = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r0['refresh_token']])->assertOk()->json('data');
        // R0 noch einmal in der Toleranz (z. B. vom Dieb): neues Paar R2.
        $r2 = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r0['refresh_token']])->assertOk()->json('data');
        $this->assertNotSame($r1['refresh_token'], $r2['refresh_token']);

        // Das verdrängte R1 taucht wieder auf: Diebstahl erkannt, Sitzung beendet.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r1['refresh_token']])->assertStatus(401);
        $this->assertNotNull($this->onAdmin(fn () => ApiDeviceSession::find($r0['session_id'])->revoked_at));
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$r2['access_token']])->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r2['refresh_token']])->assertStatus(401);
    }

    public function test_only_a_few_retries_are_tolerated(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $r0 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data');

        foreach (range(1, 6) as $i) {
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r0['refresh_token']])->assertOk();
        }
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $r0['refresh_token']])->assertStatus(401);
        $this->assertNotNull($this->onAdmin(fn () => ApiDeviceSession::find($r0['session_id'])->revoked_at));
    }
}
