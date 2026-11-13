<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ApiIdempotency: Reservierung des Schlüssels, Wirkung der Aktion und
 * gespeicherte Antwort liegen in derselben Transaktion. Ein Absturz der
 * Aktion hinterlässt weder Wirkung noch Schlüssel (die Wiederholung läuft
 * sauber neu), eine gleichzeitig laufende gleiche Anfrage ergibt 409.
 */
class AtomicIdempotencyApiTest extends MobileApiTestCase
{
    private bool $crash = true;

    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe-Endpunkt: benennt die eigene Gerätesitzung um (eine echte
        // Schreibwirkung) und stürzt auf Wunsch danach ab.
        Route::middleware(['api', 'api.auth', 'api.idempotent'])->post('/api/v1/__idempotency-probe', function () {
            $this->runs++;
            $userId = request()->user()->id;
            DB::table('api_device_session')->where('user_id', $userId)->update(['device_name' => 'Umbenannt '.$this->runs]);
            $reservation = DB::table('api_idempotency_key')->where('user_id', $userId)->value('status_code');
            if ($this->crash) {
                throw new RuntimeException('Absturz mitten in der Aktion');
            }

            return response()->json(['data' => ['run' => $this->runs, 'reservation_status' => $reservation]], 201);
        });
    }

    private function deviceName(string $userId): ?string
    {
        return $this->onAdmin(fn () => DB::table('api_device_session')->where('user_id', $userId)->value('device_name'));
    }

    private function storedKeys(string $userId): int
    {
        return $this->onAdmin(fn () => DB::table('api_idempotency_key')->where('user_id', $userId)->count());
    }

    public function test_a_crash_rolls_back_effect_and_key_and_the_retry_runs_once(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()];

        $this->postJson('/api/v1/__idempotency-probe', ['x' => 1], $headers)->assertStatus(500);
        $this->assertSame('Testgerät', $this->deviceName($user->id), 'Die Wirkung der abgestürzten Aktion darf nicht bestehen bleiben.');
        $this->assertSame(0, $this->storedKeys($user->id));

        // Wiederholung mit demselben Schlüssel: läuft genau einmal, die
        // Reservierung war während der Aktion schon da (status 0 = läuft).
        $this->crash = false;
        $first = $this->postJson('/api/v1/__idempotency-probe', ['x' => 1], $headers)->assertCreated();
        $this->assertSame(0, $first->json('data.reservation_status'));
        $this->assertSame('Umbenannt 2', $this->deviceName($user->id));

        $replay = $this->postJson('/api/v1/__idempotency-probe', ['x' => 1], $headers)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(2, $this->runs);
        $this->assertSame(1, $this->storedKeys($user->id));
        $this->assertSame(201, (int) $this->onAdmin(fn () => DB::table('api_idempotency_key')->where('user_id', $user->id)->value('status_code')));
    }

    public function test_the_same_request_running_in_another_connection_gets_409(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $key = (string) Str::uuid();
        $this->crash = false;

        // Eine andere Datenbank-Sitzung bearbeitet denselben Schlüssel gerade.
        $lock = "api-idem:{$user->id}:{$key}";
        DB::connection('pgsql_admin')->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lock]);
        try {
            $this->postJson('/api/v1/__idempotency-probe', ['x' => 1], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key])
                ->assertStatus(409)->assertJsonPath('code', 'CONFLICT')->assertHeader('Retry-After', '5');
        } finally {
            DB::connection('pgsql_admin')->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lock]);
        }

        $this->assertSame(0, $this->runs);
        $this->assertSame(0, $this->storedKeys($user->id));

        $this->postJson('/api/v1/__idempotency-probe', ['x' => 1], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key])->assertCreated();
        $this->assertSame(1, $this->runs);
    }
}
