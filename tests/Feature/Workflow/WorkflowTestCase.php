<?php

namespace Tests\Feature\Workflow;

use App\Commerce\Signature;
use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Workflow\WorkflowSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Gemeinsame Helfer der Workflow-Tests (WF-CUST1-09, docs/workflow/README.md):
 * Commerce-Aufrufe des Shops, signierte Workflow-Aufrufe des Connectors,
 * Aufräumen der Workflow-Tabellen.
 */
abstract class WorkflowTestCase extends TestCase
{
    use InteractsWithTenants;

    protected const COMMERCE_SECRET = 'commerce-secret-123';

    protected const WORKFLOW_SECRET = 'workflow-secret-0123456789';

    protected const OUTBOUND_SECRET = 'outbound-secret-0123456789';

    protected const ENDPOINT = 'https://shop.example.test/wp-json/boatingschool-workflow/v1/events';

    protected string $shopId;

    private Carbon $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond();
        $this->shopId = 'wf-'.Str::lower(Str::random(8));
        config([
            'commerce.keys' => ['shop' => self::COMMERCE_SECRET],
            'commerce.shop_id' => $this->shopId,
            'workflow.enabled' => true,
            'workflow.dispatch' => 'none',
            'workflow.endpoint' => self::ENDPOINT,
            'workflow.outbound_key_id' => 'app-workflow',
            'workflow.outbound_secret' => self::OUTBOUND_SECRET,
            'workflow.keys' => ['workflow' => ['secret' => self::WORKFLOW_SECRET, 'scopes' => ['tenant']]],
            'workflow.actions.school_admin.notice' => ['enabled' => true, 'daily_limit' => 20],
            'workflow.actions.learning_group.invite' => ['enabled' => false, 'daily_limit' => 50],
        ]);
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            DB::table('workflow_event')->where('created_at', '>=', $this->startedAt)->delete();
            DB::table('workflow_command')->where('created_at', '>=', $this->startedAt)->delete();
            DB::table('workflow_nonce')->where('expires_at', '>=', $this->startedAt)->delete();
            $accountIds = CommerceShopAccount::where('shop_id', $this->shopId)->pluck('id');
            CommerceEvent::whereIn('shop_account_id', $accountIds)->delete();
            $batchIds = CouponBatch::withoutGlobalScopes()->where('shop_id', $this->shopId)->pluck('id');
            Coupon::withoutGlobalScopes()->whereIn('batch_id', $batchIds)->delete();
            CouponBatch::withoutGlobalScopes()->whereIn('id', $batchIds)->delete();
            CommerceShopAccount::whereIn('id', $accountIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Signierter Aufruf des Shops an die Commerce-API (wie CommerceApiTest). */
    protected function commerce(string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $uri = '/api/commerce/v1'.$path;
        $body = $data === [] && $method === 'GET' ? '' : json_encode($data);

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars(
            Signature::headers('shop', self::COMMERCE_SECRET, $method, $uri, $body) + ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers
        ), $body);
    }

    /** Signierter Aufruf des Workflow Connectors. */
    protected function workflow(string $endpoint, array $data, string $keyId = 'workflow', string $secret = self::WORKFLOW_SECRET, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($data);

        return $this->workflowRaw($endpoint, $body, WorkflowSignature::headers($keyId, $secret, $body, $timestamp));
    }

    protected function workflowRaw(string $endpoint, string $body, array $headers, string $query = ''): TestResponse
    {
        return $this->call('POST', '/api/integrations/v1/workflow/'.$endpoint.$query, [], [], [], $this->transformHeadersToServerVars(
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        ), $body);
    }

    protected function command(array $overrides = []): array
    {
        return $overrides + [
            'op' => 'command',
            'action' => 'school_admin.notice',
            'idempotency_key' => 'wfc-step-'.Str::lower(Str::random(24)),
            'scope' => 'tenant',
            'params' => ['template' => 'codes_delivered'],
        ];
    }

    /** @return array{0: Tenant, 1: User, 2: string, 3: CommerceShopAccount} Schule, Admin, Shop-Kundennummer, verknüpftes Shopkonto */
    protected function linkedAccount(array $registration = []): array
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $customerId = (string) random_int(1000, 999999);
        $this->commerce('POST', '/accounts', $registration + [
            'shop_customer_id' => $customerId, 'email' => 'shop-'.Str::lower(Str::random(6)).'@example.test', 'company_name' => 'Bootsschule X',
        ])->assertCreated();
        $this->actingAsInTenant($admin, $tenant)->post('/admin/shop-link-code')->assertRedirect()->assertSessionHas('shopLinkCode');
        $code = (string) session('shopLinkCode');
        $this->commerce('POST', "/accounts/{$customerId}/link", ['link_code' => $code])->assertOk()->assertJsonPath('data.status', 'linked');
        $account = $this->onAdmin(fn () => CommerceShopAccount::where('shop_id', $this->shopId)->where('shop_customer_id', $customerId)->firstOrFail());

        return [$tenant, $admin, $customerId, $account];
    }

    /** @return Collection<int, WorkflowEvent> */
    protected function events(?string $type = null)
    {
        return $this->onAdmin(fn () => WorkflowEvent::query()->where('created_at', '>=', $this->startedAt)
            ->when($type, fn ($q) => $q->where('type', $type))->orderBy('created_at')->get());
    }
}
