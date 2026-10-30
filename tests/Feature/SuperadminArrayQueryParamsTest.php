<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Array-Werte in der Adresszeile, Superadmin): ?q[]=x bzw.
 * ?search[]=x endeten in "Array to string conversion" (HTTP 500).
 */
class SuperadminArrayQueryParamsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_array_shaped_search_params_are_ignored(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get(route('superadmin.users.index', ['q' => ['a']]))->assertOk();
        $this->actingAs($superadmin)->get(route('superadmin.coupons.index', ['search' => ['a']]))->assertOk();
    }
}
