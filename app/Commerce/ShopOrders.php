<?php

namespace App\Commerce;

use App\Api\ApiProblem;
use App\Models\AuditLog;
use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Models\TenantCourseDisabled;
use Illuminate\Support\Facades\DB;

/**
 * Lieferaufträge des Shops (nach bestätigter Zahlung): genau ein Code-Batch
 * je Bestellposition, gebunden an Schule und Kurs/Produkt, abgelegt im
 * Codebestand der Schule. Codewerte verlassen die Plattform nicht und
 * erscheinen in keinem Protokoll.
 */
class ShopOrders
{
    public const KINDS = ['course', 'renewal', 'product'];

    /**
     * @param  array{order_id: string, order_line_id: string, order_number?: ?string, kind: string, course_code?: ?string, product_code?: ?string, option_product_codes?: ?array, quantity: int}  $line
     * @return array{0: CouponBatch, 1: bool} Batch und ob er neu angelegt wurde
     */
    public function deliver(string $shopId, CommerceShopAccount $account, array $line): array
    {
        // Fingerabdruck aus den Angaben der Anfrage selbst: eine Wiederholung
        // erkennt die App so auch dann, wenn sich seitdem etwas geändert hat
        // (Verknüpfung gelöst, Kurs abgeschaltet) -- geliefert ist geliefert.
        $options = array_values(array_unique(array_map('strval', $line['option_product_codes'] ?? [])));
        sort($options);
        $fingerprint = hash('sha256', json_encode([
            $account->id, $line['kind'], $line['course_code'] ?? null, $line['product_code'] ?? null, $options, (int) $line['quantity'],
        ]));

        return DB::transaction(function () use ($shopId, $account, $line, $fingerprint) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['commerce-line:'.$shopId.':'.$line['order_id'].':'.$line['order_line_id']]);
            $existing = CouponBatch::withoutGlobalScopes()->where('shop_id', $shopId)
                ->where('order_id', $line['order_id'])->where('order_line_id', $line['order_line_id'])->first();
            if ($existing) {
                // Wiederholung nach Timeout: derselbe Batch, nie neue Codes.
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new ApiProblem(409, 'ORDER_LINE_CONFLICT', 'Bestellposition schon anders geliefert',
                        'Für diese Bestellposition gibt es bereits einen Batch mit anderem Inhalt.');
                }

                return [$existing, false];
            }

            if (! $account->canPurchase()) {
                throw new ApiProblem(403, 'ACCOUNT_NOT_LINKED', 'Kein Einkauf für eine Schule möglich', 'Das Shopkonto ist (noch) nicht mit einer aktiven Schule verknüpft.');
            }
            $target = $this->resolveTarget($account, $line);

            $batch = CouponBatch::create([
                'tenant_id' => $account->tenant_id,
                'kind' => $line['kind'],
                'course_id' => $target['course']?->id,
                'product_id' => $target['product']?->id,
                'option_product_ids' => $target['option_product_ids'] ?: null,
                'quantity' => (int) $line['quantity'],
                'source' => 'woocommerce',
                'shop_id' => $shopId,
                'order_id' => $line['order_id'],
                'order_line_id' => $line['order_line_id'],
                'order_number' => $line['order_number'] ?? null,
                'shop_account_id' => $account->id,
                'request_fingerprint' => $fingerprint,
                'status' => 'delivered',
                'created_at' => now(),
            ]);

            $label = 'Shop-Bestellung '.($line['order_number'] ?? $line['order_id']);
            for ($i = 0; $i < (int) $line['quantity']; $i++) {
                $coupon = Coupon::create([
                    'code' => Coupon::generateUniqueCode(),
                    'course_id' => $target['course']?->id,
                    'product_id' => $target['product']?->id,
                    'is_renewal' => $line['kind'] === 'renewal',
                    'tenant_id' => $account->tenant_id,
                    'batch_label' => $label,
                    'batch_id' => $batch->id,
                    'created_by_user_id' => null,
                ]);
                if ($target['option_product_ids']) {
                    $coupon->optionProducts()->attach($target['option_product_ids']);
                }
            }

            AuditLog::record($account->tenant_id, null, 'commerce.batch.delivered', 'coupon_batch', $batch->id, null, [
                'order_number' => $batch->order_number, 'kind' => $batch->kind, 'quantity' => $batch->quantity,
            ]);
            CommerceEvent::logIncoming('batch.delivered', $account, $line['order_id'].':'.$line['order_line_id'], [
                'batch_id' => $batch->id, 'kind' => $batch->kind, 'quantity' => $batch->quantity,
            ]);

            return [$batch, true];
        });
    }

    /**
     * Unbenutzte Codes eines Batches sperren (Rückerstattung/Storno). Eingelöste
     * Codes bleiben unangetastet. Mit $quantity höchstens so viele.
     *
     * @return array{voided: int, already_voided: int, redeemed: int, open: int}
     */
    public function void(CouponBatch $batch, string $reason, ?int $quantity, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($batch, $reason, $quantity, $idempotencyKey) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['commerce-void:'.$batch->id]);
            // Derselbe Storno-Aufruf noch einmal: gleiches Ergebnis, keine weiteren Sperren.
            $previous = CommerceEvent::where('type', 'batch.voided')->where('correlation_id', $idempotencyKey)
                ->where('payload->batch_id', $batch->id)->first();
            if ($previous) {
                return $previous->payload['result'];
            }

            // Zeilen sperren, damit eine gleichzeitige Einlösung nicht zwischen
            // Auswahl und Sperre fällt (Einlösungen sperren dieselbe Zeile).
            $ids = Coupon::withoutGlobalScopes()->where('batch_id', $batch->id)->whereNull('redeemed_at')->whereNull('voided_at')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->when($quantity, fn ($q) => $q->limit($quantity))->lockForUpdate()->pluck('id');
            $voided = $ids->isEmpty() ? 0 : Coupon::withoutGlobalScopes()->whereIn('id', $ids)
                ->whereNull('redeemed_at')->whereNull('voided_at')->update(['voided_at' => now()]);

            $result = $this->counts($batch) + ['voided' => $voided];
            if ($voided > 0) {
                $batch->forceFill([
                    // Nichts mehr offen und nichts eingelöst: ganz gesperrt; sonst teilweise.
                    'status' => $result['open'] === 0 && $result['redeemed'] === 0 ? 'voided' : 'partially_voided',
                    'void_reason' => mb_substr($reason, 0, 500),
                    'voided_at' => now(),
                ])->save();
            }

            AuditLog::record($batch->tenant_id, null, 'commerce.batch.voided', 'coupon_batch', $batch->id, null, [
                'order_number' => $batch->order_number, 'voided' => $voided, 'reason' => mb_substr($reason, 0, 200),
            ]);
            CommerceEvent::create([
                'direction' => 'in', 'type' => 'batch.voided', 'shop_account_id' => $batch->shop_account_id, 'tenant_id' => $batch->tenant_id,
                'correlation_id' => $idempotencyKey, 'payload' => ['batch_id' => $batch->id, 'result' => $result],
                'status' => 'done', 'created_at' => now(), 'processed_at' => now(),
            ]);

            return $result;
        });
    }

    /** @return array{quantity: int, redeemed: int, already_voided: int, open: int} */
    public function counts(CouponBatch $batch): array
    {
        $row = Coupon::withoutGlobalScopes()->where('batch_id', $batch->id)
            ->selectRaw('count(*) as total, count(redeemed_at) as redeemed, count(*) filter (where voided_at is not null and redeemed_at is null) as voided')
            ->first();

        return [
            'quantity' => (int) $row->total,
            'redeemed' => (int) $row->redeemed,
            'already_voided' => (int) $row->voided,
            'open' => (int) $row->total - (int) $row->redeemed - (int) $row->voided,
        ];
    }

    public function present(CouponBatch $batch): array
    {
        $counts = $this->counts($batch);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'delivery' => 'platform',
            'kind' => $batch->kind,
            'course_code' => $batch->course_id ? CourseDefinition::withoutGlobalScopes()->whereKey($batch->course_id)->value('code') : null,
            'product_code' => $batch->product_id ? Product::whereKey($batch->product_id)->value('code') : null,
            'order_id' => $batch->order_id,
            'order_line_id' => $batch->order_line_id,
            'tenant_id' => $batch->tenant_id,
            'quantity' => $batch->quantity,
            'redeemed' => $counts['redeemed'],
            'voided' => $counts['already_voided'],
            'open' => $counts['open'],
            'created_at' => $batch->created_at?->toIso8601ZuluString(),
        ];
    }

    /** Was der Shop Produkten zuordnen kann (Kurse und Produkte der Plattform). */
    public function catalog(): array
    {
        return [
            'courses' => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('status', 'published')->where('site_enabled', true)
                ->orderBy('name')->get(['code', 'name'])->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])->values(),
            'products' => Product::where('active', true)->orderBy('name')->get(['code', 'name'])
                ->map(fn ($p) => ['code' => $p->code, 'name' => $p->name])->values(),
            'kinds' => self::KINDS,
        ];
    }

    /** @return array{course: ?CourseDefinition, product: ?Product, option_product_ids: list<string>} */
    private function resolveTarget(CommerceShopAccount $account, array $line): array
    {
        $course = null;
        $product = null;
        if (in_array($line['kind'], ['course', 'renewal'], true)) {
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('status', 'published')->where('code', $line['course_code'] ?? '')->first();
            if (! $course) {
                throw new ApiProblem(422, 'UNKNOWN_COURSE', 'Unbekannter Kurs', 'Kein veröffentlichter Kurs mit diesem Code.', ['course_code' => ['Unbekannter Kurs.']]);
            }
            // Plattformweit abgeschaltet: Codes ließen sich nicht einlösen.
            if (! $course->site_enabled) {
                throw new ApiProblem(422, 'COURSE_NOT_AVAILABLE', 'Kurs derzeit nicht verfügbar', 'Der Kurs wird auf der Plattform derzeit nicht angeboten.');
            }
            if (TenantCourseDisabled::withoutGlobalScopes()->where('tenant_id', $account->tenant_id)->where('course_id', $course->id)->exists()) {
                throw new ApiProblem(422, 'COURSE_NOT_AVAILABLE', 'Kurs für die Schule abgeschaltet', 'Die Schule hat diesen Kurs abgeschaltet.');
            }
        } else {
            $product = Product::where('active', true)->where('code', $line['product_code'] ?? '')->first();
            if (! $product) {
                throw new ApiProblem(422, 'UNKNOWN_PRODUCT', 'Unbekanntes Produkt', 'Kein aktives Produkt mit diesem Code.', ['product_code' => ['Unbekanntes Produkt.']]);
            }
        }

        $optionIds = [];
        if (! empty($line['option_product_codes'])) {
            if ($line['kind'] !== 'course') {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['option_product_codes' => ['Zusatzprodukte gibt es nur zu Kurscodes.']]);
            }
            $options = Product::where('active', true)->whereIn('code', $line['option_product_codes'])->pluck('id', 'code');
            if ($options->count() !== count(array_unique($line['option_product_codes']))) {
                throw new ApiProblem(422, 'UNKNOWN_PRODUCT', 'Unbekanntes Zusatzprodukt', null, ['option_product_codes' => ['Unbekanntes oder inaktives Produkt.']]);
            }
            $optionIds = $options->values()->sort()->values()->all();
        }

        return ['course' => $course, 'product' => $product, 'option_product_ids' => $optionIds];
    }
}
