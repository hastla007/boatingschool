<?php

namespace App\Http\Controllers\Commerce;

use App\Api\ApiProblem;
use App\Commerce\ShopAccounts;
use App\Commerce\ShopOrders;
use App\Http\Controllers\Controller;
use App\Models\CouponBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Commerce-API v1 (Shop -> App, Server-zu-Server, signiert). Siehe
 * docs/commerce/README.md. Läuft auf der Owner-Verbindung.
 */
class CommerceController extends Controller
{
    public function __construct(private ShopAccounts $accounts, private ShopOrders $orders) {}

    private function shopId(Request $request): string
    {
        return (string) $request->attributes->get('commerce_shop_id');
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->orders->catalog()]);
    }

    public function registerAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_customer_id' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'billing_country' => ['nullable', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
        ]);
        $account = $this->accounts->register($this->shopId($request), $data);

        return response()->json(['data' => $this->accounts->present($account)], $account->wasRecentlyCreated ? 201 : 200);
    }

    public function showAccount(Request $request, string $customerId): JsonResponse
    {
        return response()->json(['data' => $this->accounts->present($this->accounts->find($this->shopId($request), $customerId))]);
    }

    public function updateAccount(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'billing_country' => ['nullable', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
        ]);
        $account = $this->accounts->updateShopData($this->accounts->find($this->shopId($request), $customerId), $data);

        return response()->json(['data' => $this->accounts->present($account)]);
    }

    public function linkAccount(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate(['link_code' => ['required', 'string', 'max:20']]);
        $account = $this->accounts->confirmLink($this->accounts->find($this->shopId($request), $customerId), $data['link_code']);

        return response()->json(['data' => $this->accounts->present($account)]);
    }

    public function deactivateAccount(Request $request, string $customerId): JsonResponse
    {
        $account = $this->accounts->deactivate($this->accounts->find($this->shopId($request), $customerId));

        return response()->json(['data' => $this->accounts->present($account)]);
    }

    /** Lieferauftrag einer bezahlten Bestellposition (idempotent je Bestellung + Position). */
    public function deliver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_customer_id' => ['required', 'string', 'max:64'],
            'order_id' => ['required', 'string', 'max:64'],
            'order_line_id' => ['required', 'string', 'max:64'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'kind' => ['required', 'in:'.implode(',', ShopOrders::KINDS)],
            'course_code' => ['nullable', 'required_if:kind,course,renewal', 'string', 'max:40'],
            'product_code' => ['nullable', 'required_if:kind,product', 'string', 'max:60'],
            'option_product_codes' => ['nullable', 'array', 'max:10'],
            'option_product_codes.*' => ['string', 'max:60'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.(int) config('commerce.max_quantity', 500)],
            'delivery' => ['nullable', 'in:platform'],
        ]);
        $account = $this->accounts->find($this->shopId($request), $data['shop_customer_id']);
        [$batch, $created] = $this->orders->deliver($this->shopId($request), $account, $data);

        return response()->json(['data' => $this->orders->present($batch)], $created ? 201 : 200);
    }

    public function showOrderLine(Request $request, string $orderId, string $lineId): JsonResponse
    {
        $batch = CouponBatch::withoutGlobalScopes()->where('shop_id', $this->shopId($request))->where('order_id', $orderId)->where('order_line_id', $lineId)->first();
        if (! $batch) {
            throw new ApiProblem(404, 'BATCH_NOT_FOUND', 'Noch nicht geliefert', 'Für diese Bestellposition gibt es (noch) keinen Batch.');
        }

        return response()->json(['data' => $this->orders->present($batch)]);
    }

    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        return response()->json(['data' => $this->orders->present($this->ownBatch($request, $batchId))]);
    }

    /** Unbenutzte Codes sperren (Rückerstattung/Storno). Idempotency-Key pflicht. */
    public function voidBatch(Request $request, string $batchId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('commerce.max_quantity', 500)],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        if (! Str::isUuid($key) && ! preg_match('/^[A-Za-z0-9:_\-]{8,64}$/', $key)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['Idempotency-Key' => ['Bitte einen Idempotency-Key mitsenden (z. B. die Rückerstattungs-ID).']]);
        }
        $batch = $this->ownBatch($request, $batchId);
        $result = $this->orders->void($batch, $data['reason'], $data['quantity'] ?? null, $key);

        return response()->json(['data' => $this->orders->present($batch->fresh()) + ['voided_now' => $result['voided']]]);
    }

    private function ownBatch(Request $request, string $batchId): CouponBatch
    {
        $batch = CouponBatch::withoutGlobalScopes()->where('id', $batchId)->where('shop_id', $this->shopId($request))->first();
        if (! $batch) {
            throw new ApiProblem(404, 'BATCH_NOT_FOUND', 'Batch nicht gefunden');
        }

        return $batch;
    }
}
