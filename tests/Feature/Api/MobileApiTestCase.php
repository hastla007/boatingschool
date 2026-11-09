<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Hilfen für Tests der Mobile-API v1. */
abstract class MobileApiTestCase extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdUserIds as $id) {
                DB::table('api_idempotency_key')->where('user_id', $id)->delete();
                DB::table('api_device_session')->where('user_id', $id)->delete();
            }
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Anmelden und Access-Token liefern. */
    protected function apiLogin(User $user, string $password = 'password'): string
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'Testgerät']);
        $response->assertOk();

        return $response->json('data.access_token');
    }

    protected function api(string $method, string $uri, User|string $auth, ?Tenant $tenant = null, array $data = [], array $headers = []): TestResponse
    {
        $token = $auth instanceof User ? $this->apiLogin($auth) : $auth;
        $headers = ['Authorization' => 'Bearer '.$token] + ($tenant ? ['X-Tenant-Slug' => $tenant->slug] : []) + $headers;
        if (in_array(strtoupper($method), ['POST'], true) && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->json($method, '/api/v1'.$uri, $data, $headers);
    }
}
