<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponBatch;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Der eine Weg, Zugangscodes anzulegen (Superadmin „Codes erzeugen“ und
 * Lieferung einer Shop-Bestellposition, siehe ShopOrders::deliver):
 *
 * - alles in einer Transaktion (bricht etwas ab, bleibt kein halbes Paket),
 * - Codes werden in PHP erzeugt, gesammelt gegen den Bestand geprüft und in
 *   Blöcken per insert() geschrieben (statt einer Abfrage je Code),
 * - Zusatzprodukte werden ebenfalls gesammelt angehängt,
 * - kollidiert ein Code trotzdem (gleichzeitige Erzeugung), wird nur der
 *   betroffene Block mit neuen Codes wiederholt (Savepoint).
 *
 * Codewerte gibt nur der Rückgabewert heraus -- nie Protokolle oder Ereignisse.
 */
class CouponBatchFactory
{
    /** Zeilen je insert() (9 Spalten -> weit unter dem Parameterlimit von PostgreSQL). */
    public const CHUNK_SIZE = 500;

    /** Versuche je Block bei einer Kollision mit einem gleichzeitig angelegten Code. */
    private const MAX_ATTEMPTS = 5;

    /**
     * @param  array{kind: 'course'|'renewal'|'product', course_id?: ?string, product_id?: ?string, option_product_ids?: list<string>, tenant_id?: ?string, batch_label?: ?string, created_by_user_id?: ?string, expires_at?: ?\DateTimeInterface}  $target
     * @param  array<string, mixed>|null  $batch  Spalten eines coupon_batch-Eintrags (z. B. source, Shop-Angaben); null = kein Paket-Eintrag
     * @return array{batch: ?CouponBatch, codes: list<string>} Codes in Reihenfolge der Anlage
     */
    public function create(array $target, int $quantity, ?array $batch = null): array
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Die Menge muss mindestens 1 sein.');
        }
        $kind = $target['kind'];
        $optionIds = $kind === 'course' ? array_values(array_unique($target['option_product_ids'] ?? [])) : [];
        $courseId = $kind === 'product' ? null : ($target['course_id'] ?? null);
        $productId = $kind === 'product' ? ($target['product_id'] ?? null) : null;

        return $this->connection()->transaction(function () use ($target, $quantity, $batch, $kind, $optionIds, $courseId, $productId) {
            $batchModel = null;
            if ($batch !== null) {
                $batchModel = CouponBatch::create($batch + [
                    'tenant_id' => $target['tenant_id'] ?? null,
                    'kind' => $kind,
                    'course_id' => $courseId,
                    'product_id' => $productId,
                    'option_product_ids' => $optionIds ?: null,
                    'quantity' => $quantity,
                    'status' => 'delivered',
                    'created_at' => now(),
                ]);
            }

            $expiresAt = $target['expires_at'] ?? null;
            $row = [
                'course_id' => $courseId,
                'product_id' => $productId,
                'is_renewal' => $kind === 'renewal',
                'tenant_id' => $target['tenant_id'] ?? null,
                'batch_label' => $target['batch_label'] ?? null,
                'batch_id' => $batchModel?->id,
                'created_by_user_id' => $target['created_by_user_id'] ?? null,
                'expires_at' => $expiresAt ? Carbon::instance($expiresAt)->utc() : null,
            ];

            $codes = [];
            for ($done = 0; $done < $quantity; $done += self::CHUNK_SIZE) {
                array_push($codes, ...$this->insertChunk($row, min(self::CHUNK_SIZE, $quantity - $done), $optionIds));
            }

            return ['batch' => $batchModel, 'codes' => $codes];
        });
    }

    /** @return list<string> */
    private function insertChunk(array $row, int $count, array $optionIds): array
    {
        for ($attempt = 1; ; $attempt++) {
            $codes = $this->freshCodes($count);
            $model = new Coupon;
            $rows = [];
            $optionRows = [];
            foreach ($codes as $code) {
                // Zeitlich geordnete IDs wie bei Coupon::create (Sperren nach
                // Rückerstattung nehmen die zuletzt erzeugten Codes).
                $id = $model->newUniqueId();
                $rows[] = ['id' => $id, 'code' => $code] + $row;
                foreach ($optionIds as $optionId) {
                    $optionRows[] = ['coupon_id' => $id, 'product_id' => $optionId];
                }
            }

            try {
                // Savepoint: eine Kollision verwirft nur diesen Block.
                $this->connection()->transaction(function () use ($rows, $optionRows) {
                    Coupon::query()->insert($rows);
                    foreach (array_chunk($optionRows, self::CHUNK_SIZE * 2) as $chunk) {
                        $this->connection()->table('coupon_option_product')->insert($chunk);
                    }
                });

                return $codes;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** @return list<string> $count verschiedene Codes, die es im Bestand noch nicht gibt. */
    private function freshCodes(int $count): array
    {
        $codes = [];
        while (count($codes) < $count) {
            while (count($codes) < $count) {
                $codes[$this->randomCode()] = true;
            }
            foreach ($this->existingCodes(array_keys($codes)) as $taken) {
                unset($codes[$taken]);
            }
        }

        return array_map('strval', array_keys($codes));
    }

    /**
     * Welche der Codes es schon gibt (alle Schulen: Commerce und Superadmin
     * laufen auf der Owner-Verbindung).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    protected function existingCodes(array $codes): array
    {
        return Coupon::withoutGlobalScopes()->whereIn('code', $codes)->pluck('code')->all();
    }

    protected function randomCode(): string
    {
        return Coupon::randomCode();
    }

    private function connection(): ConnectionInterface
    {
        return Coupon::query()->getConnection();
    }
}
