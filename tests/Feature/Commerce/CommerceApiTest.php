<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Signature;
use App\Models\CommerceEvent;
use App\Models\CommerceLinkCode;
use App\Models\CommerceShopAccount;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantCourseDisabled;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Commerce-API: signierte Aufrufe des B2B-Shops (docs/commerce/README.md). */
class CommerceApiTest extends TestCase
{
    use InteractsWithTenants;

    private string $shopId;

    private array $productIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopId = 'test-'.Str::lower(Str::random(8));
        config(['commerce.keys' => ['shop' => 'test-secret-123'], 'commerce.shop_id' => $this->shopId]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $accountIds = CommerceShopAccount::where('shop_id', $this->shopId)->pluck('id');
            CommerceEvent::whereIn('shop_account_id', $accountIds)->delete();
            $batchIds = CouponBatch::withoutGlobalScopes()->where('shop_id', $this->shopId)->pluck('id');
            Coupon::withoutGlobalScopes()->whereIn('batch_id', $batchIds)->delete();
            CouponBatch::withoutGlobalScopes()->whereIn('id', $batchIds)->delete();
            CommerceShopAccount::whereIn('id', $accountIds)->delete();
            Product::whereIn('id', $this->productIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function signed(string $method, string $path, array $data = [], array $headers = [], ?string $secret = null): TestResponse
    {
        $uri = '/api/commerce/v1'.$path;
        $body = $data === [] && $method === 'GET' ? '' : json_encode($data);

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars(
            Signature::headers('shop', $secret ?? 'test-secret-123', $method, $uri, $body) + ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers
        ), $body);
    }

    /** Schule mit Admin, der einen Verknüpfungscode erzeugt; Shopkonto registriert und verknüpft. */
    private function linkedAccount(): array
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $customerId = (string) random_int(1000, 999999);
        $this->signed('POST', '/accounts', ['shop_customer_id' => $customerId, 'email' => 'shop-'.Str::random(6).'@example.test', 'company_name' => 'Bootsschule X'])->assertCreated();
        $code = $this->linkCode($tenant, $admin);
        $this->signed('POST', "/accounts/{$customerId}/link", ['link_code' => $code])->assertOk()->assertJsonPath('data.status', 'linked');

        return [$tenant, $admin, $customerId];
    }

    private function linkCode(Tenant $tenant, User $admin): string
    {
        $this->actingAsInTenant($admin, $tenant)->post('/admin/shop-link-code')->assertRedirect()->assertSessionHas('shopLinkCode');

        return session('shopLinkCode');
    }

    public function test_only_correctly_signed_fresh_requests_are_accepted(): void
    {
        $this->getJson('/api/commerce/v1/catalog')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->signed('GET', '/catalog', [], [], 'falsches-geheimnis')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->signed('GET', '/catalog')->assertOk()->assertJsonStructure(['data' => ['courses', 'products', 'kinds']]);

        // Wiederholte Nonce: abgelehnt.
        $uri = '/api/commerce/v1/catalog';
        $headers = $this->transformHeadersToServerVars(Signature::headers('shop', 'test-secret-123', 'GET', $uri, '') + ['Accept' => 'application/json']);
        $this->call('GET', $uri, [], [], [], $headers, '')->assertOk();
        $this->call('GET', $uri, [], [], [], $headers, '')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_REPLAYED');

        // Alter Zeitstempel: abgelehnt.
        $ts = (string) (time() - 3600);
        $old = [Signature::HEADER_KEY => 'shop', Signature::HEADER_TIMESTAMP => $ts, Signature::HEADER_NONCE => Str::random(20),
            Signature::HEADER_SIGNATURE => Signature::sign('test-secret-123', 'GET', $uri, $ts, 'x', '')];
        $old[Signature::HEADER_SIGNATURE] = Signature::sign('test-secret-123', 'GET', $uri, $ts, $old[Signature::HEADER_NONCE], '');
        $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars($old + ['Accept' => 'application/json']), '')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_EXPIRED');

        // Ohne konfiguriertes Geheimnis ist die Schnittstelle zu.
        config(['commerce.keys' => []]);
        $this->signed('GET', '/catalog')->assertStatus(401);
    }

    public function test_registration_creates_a_pending_app_account_but_never_links_by_email(): void
    {
        $email = 'neu-'.Str::lower(Str::random(6)).'@example.test';
        $this->signed('POST', '/accounts', ['shop_customer_id' => '501', 'email' => strtoupper($email), 'first_name' => 'Anna', 'last_name' => 'Kai', 'company_name' => 'Segelschule Nord', 'billing_country' => 'de'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.can_purchase', false)->assertJsonPath('data.tenant', null);
        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->createdUserIds[] = $user->id;
        $this->assertSame('invited', $user->status);
        // Wiederholung: dasselbe Konto.
        $this->signed('POST', '/accounts', ['shop_customer_id' => '501', 'email' => $email])->assertOk();
        $this->assertSame(1, $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->count()));

        // Adresse gehört schon zu einem App-Konto: keine Zuordnung über die E-Mail.
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->signed('POST', '/accounts', ['shop_customer_id' => '502', 'email' => $admin->email])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_link')->assertJsonPath('data.app_user_id', null)->assertJsonPath('data.tenant', null);

        // Stammdaten aus dem Shop ändern das App-Konto nicht.
        $this->signed('PATCH', '/accounts/501', ['email' => 'andere@example.test', 'company_name' => 'Segelschule Süd'])->assertOk();
        $this->assertSame($email, $this->onAdmin(fn () => User::find($user->id)->email));
        $this->assertSame('Segelschule Süd', $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->where('shop_customer_id', '501')->value('company_name')));
    }

    public function test_link_code_binds_the_school_once_and_wrong_codes_are_limited(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->signed('POST', '/accounts', ['shop_customer_id' => '601', 'email' => $admin->email])->assertCreated();

        for ($i = 0; $i < 5; $i++) {
            $this->signed('POST', '/accounts/601/link', ['link_code' => 'AAAA-BBBB'])->assertStatus(422)->assertJsonPath('code', 'LINK_CODE_INVALID');
        }
        $this->signed('POST', '/accounts/601/link', ['link_code' => 'AAAA-BBBB'])->assertStatus(429);
        $this->onAdmin(fn () => RateLimiter::clear('commerce-link:'.CommerceShopAccount::where('shop_id', $this->shopId)->value('id')));

        $code = $this->linkCode($tenant, $admin);
        $this->signed('POST', '/accounts/601/link', ['link_code' => strtolower($code)])->assertOk()
            ->assertJsonPath('data.status', 'linked')->assertJsonPath('data.can_purchase', true)
            ->assertJsonPath('data.tenant.id', $tenant->id)->assertJsonPath('data.app_user_id', $admin->id);
        // Einmalig.
        $this->signed('POST', '/accounts', ['shop_customer_id' => '602', 'email' => 'x-'.Str::random(5).'@example.test'])->assertCreated();
        $this->signed('POST', '/accounts/602/link', ['link_code' => $code])->assertStatus(422);

        // Abgelaufener Code.
        $expired = $this->linkCode($tenant, $admin);
        $this->onAdmin(fn () => CommerceLinkCode::withoutGlobalScopes()->where('code_hash', CommerceLinkCode::hash($expired))->update(['expires_at' => now()->subMinute()]));
        $this->signed('POST', '/accounts/602/link', ['link_code' => $expired])->assertStatus(422);

        // Nur Inhaber/Admins dürfen Codes erzeugen.
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->actingAsInTenant($learner, $tenant)->post('/admin/shop-link-code')->assertForbidden();

        // Nicht mehr Admin: kein Einkauf mehr.
        $this->onAdmin(fn () => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $admin->id)->update(['role' => 'learner']));
        $this->signed('GET', '/accounts/601')->assertOk()->assertJsonPath('data.can_purchase', false);
    }

    public function test_paid_order_line_creates_exactly_one_batch_in_the_school_stock(): void
    {
        [$tenant, , $customerId] = $this->linkedAccount();
        $line = ['shop_customer_id' => $customerId, 'order_id' => '9001', 'order_line_id' => '1', 'order_number' => 'B-9001', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 3];

        $first = $this->signed('POST', '/orders/lines', $line)->assertCreated()
            ->assertJsonPath('data.quantity', 3)->assertJsonPath('data.open', 3)->assertJsonPath('data.course_code', 'SRC')->assertJsonPath('data.delivery', 'platform');
        $this->assertStringNotContainsString('"code"', $first->getContent(), 'keine Codewerte in der Antwort');
        $batchId = $first->json('data.batch_id');

        // Wiederholung nach Timeout: derselbe Batch, keine neuen Codes.
        $this->signed('POST', '/orders/lines', $line)->assertOk()->assertJsonPath('data.batch_id', $batchId);
        $this->signed('GET', '/orders/9001/lines/1')->assertOk()->assertJsonPath('data.batch_id', $batchId);
        $this->assertSame(3, $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $batchId)->where('tenant_id', $tenant->id)->count()));
        // Gleiche Position, anderer Inhalt: Konflikt.
        $this->signed('POST', '/orders/lines', ['quantity' => 4] + $line)->assertStatus(409)->assertJsonPath('code', 'ORDER_LINE_CONFLICT');
        $this->signed('GET', '/orders/9001/lines/2')->assertNotFound()->assertJsonPath('code', 'BATCH_NOT_FOUND');

        // Im Codebestand der Schule sichtbar.
        $admin = $this->onAdmin(fn () => User::find(CommerceShopAccount::where('shop_id', $this->shopId)->value('user_id')));
        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons')->assertOk()->assertSee('B-9001');
    }

    public function test_renewal_product_and_option_codes_and_unavailable_targets(): void
    {
        [$tenant, , $customerId] = $this->linkedAccount();
        $product = $this->onAdmin(fn () => Product::create(['code' => 'TEST-'.Str::upper(Str::random(6)), 'name' => 'Fahrstunde Test', 'active' => true]));
        $this->productIds[] = $product->id;
        $base = ['shop_customer_id' => $customerId, 'order_id' => '9100', 'quantity' => 2];

        $renewal = $this->signed('POST', '/orders/lines', ['order_line_id' => 'a', 'kind' => 'renewal', 'course_code' => 'SRC'] + $base)->assertCreated()->json('data.batch_id');
        $this->assertSame(2, $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $renewal)->where('is_renewal', true)->count()));

        $productBatch = $this->signed('POST', '/orders/lines', ['order_line_id' => 'b', 'kind' => 'product', 'product_code' => $product->code] + $base)->assertCreated()->json('data.batch_id');
        $this->assertSame(2, $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $productBatch)->where('product_id', $product->id)->count()));

        $withOption = $this->signed('POST', '/orders/lines', ['order_line_id' => 'c', 'kind' => 'course', 'course_code' => 'SRC', 'option_product_codes' => [$product->code]] + $base)->assertCreated()->json('data.batch_id');
        $coupon = $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $withOption)->first());
        $this->assertSame([$product->id], $this->onAdmin(fn () => $coupon->optionProducts()->pluck('product.id')->all()));

        $this->signed('POST', '/orders/lines', ['order_line_id' => 'd', 'kind' => 'course', 'course_code' => 'GIBT-ES-NICHT'] + $base)->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_COURSE');
        $course = $this->existingCourse('FKN');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));
        $this->signed('POST', '/orders/lines', ['order_line_id' => 'e', 'kind' => 'course', 'course_code' => 'FKN'] + $base)->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_AVAILABLE');
    }

    public function test_unlinked_or_unapproved_accounts_cannot_order(): void
    {
        $this->signed('POST', '/accounts', ['shop_customer_id' => '701', 'email' => 'p-'.Str::random(6).'@example.test'])->assertCreated();
        $this->createdUserIds[] = $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->value('user_id'));
        $this->signed('POST', '/orders/lines', ['shop_customer_id' => '701', 'order_id' => '1', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 1])
            ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_NOT_LINKED');
        $this->signed('POST', '/orders/lines', ['shop_customer_id' => 'unbekannt', 'order_id' => '1', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 1])
            ->assertNotFound();

        // Verknüpftes, dann vom Shop deaktiviertes Konto.
        [, , $customerId] = $this->linkedAccount();
        $this->signed('POST', "/accounts/{$customerId}/deactivate")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->signed('POST', '/orders/lines', ['shop_customer_id' => $customerId, 'order_id' => '2', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 1])
            ->assertStatus(403);
    }

    public function test_refund_voids_only_unused_codes_and_is_idempotent(): void
    {
        [$tenant, , $customerId] = $this->linkedAccount();
        $batchId = $this->signed('POST', '/orders/lines', ['shop_customer_id' => $customerId, 'order_id' => '9200', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 3])
            ->assertCreated()->json('data.batch_id');
        // Ein Code wurde schon eingelöst.
        $redeemedId = $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $batchId)->orderBy('created_at')->value('id'));
        $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->whereKey($redeemedId)->update(['redeemed_at' => now()]));

        $this->signed('POST', "/batches/{$batchId}/void", ['reason' => 'Teilrückerstattung'])->assertStatus(422);
        $this->signed('POST', "/batches/{$batchId}/void", ['reason' => 'Teilrückerstattung', 'quantity' => 1], ['Idempotency-Key' => 'refund-77'])
            ->assertOk()->assertJsonPath('data.voided_now', 1)->assertJsonPath('data.open', 1)->assertJsonPath('data.redeemed', 1);
        // Gleicher Storno noch einmal: nichts weiter gesperrt.
        $this->signed('POST', "/batches/{$batchId}/void", ['reason' => 'Teilrückerstattung', 'quantity' => 1], ['Idempotency-Key' => 'refund-77'])
            ->assertOk()->assertJsonPath('data.open', 1);
        // Komplett: der eingelöste Code bleibt.
        $this->signed('POST', "/batches/{$batchId}/void", ['reason' => 'Storno'], ['Idempotency-Key' => 'refund-78'])
            ->assertOk()->assertJsonPath('data.open', 0)->assertJsonPath('data.redeemed', 1)->assertJsonPath('data.status', 'partially_voided');
        $this->assertNull($this->onAdmin(fn () => Coupon::withoutGlobalScopes()->whereKey($redeemedId)->value('voided_at')));

        // Batch eines anderen Shops: nicht auffindbar.
        config(['commerce.shop_id' => 'anderer-shop']);
        $this->signed('GET', "/batches/{$batchId}")->assertNotFound();
    }

    public function test_events_never_contain_code_values(): void
    {
        [, , $customerId] = $this->linkedAccount();
        $batchId = $this->signed('POST', '/orders/lines', ['shop_customer_id' => $customerId, 'order_id' => '9300', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 2])->json('data.batch_id');
        $codes = $this->onAdmin(fn () => Coupon::withoutGlobalScopes()->where('batch_id', $batchId)->pluck('code'));
        $payloads = $this->onAdmin(fn () => CommerceEvent::whereIn('shop_account_id', CommerceShopAccount::where('shop_id', $this->shopId)->pluck('id'))->pluck('payload')->toJson());
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $payloads);
        }
    }
}
