<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Product;
use App\Services\CouponBatchFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ein Weg für die Code-Erzeugung (Superadmin und Shop): Sammel-Insert in
 * einer Transaktion, Paket-Eintrag für Superadmin-Pakete ab zwei Codes,
 * Wiederholung bei einer Code-Kollision.
 */
class CouponBatchFactoryTest extends TestCase
{
    use InteractsWithTenants;

    private array $labels = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $batchIds = Coupon::withoutGlobalScopes()->whereIn('batch_label', $this->labels)->whereNotNull('batch_id')->pluck('batch_id')->unique();
            Coupon::withoutGlobalScopes()->whereIn('batch_label', $this->labels)->delete();
            CouponBatch::withoutGlobalScopes()->whereIn('id', $batchIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function label(): string
    {
        return $this->labels[] = 'E2E-FACTORY-'.Str::upper(Str::random(8));
    }

    public function test_superadmin_batches_get_a_batch_row_without_shop_fields(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');
        $option = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM')->firstOrFail());
        $label = $this->label();

        $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'course:'.$course->id, 'quantity' => 3, 'batch_label' => $label, 'option_product_ids' => [$option->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', '3 Coupon-Codes erzeugt.');

        $this->onAdmin(function () use ($label, $course, $option) {
            $coupons = Coupon::where('batch_label', $label)->get();
            $this->assertCount(3, $coupons);
            $this->assertCount(1, $coupons->pluck('batch_id')->unique());
            $batch = CouponBatch::withoutGlobalScopes()->findOrFail($coupons->first()->batch_id);
            $this->assertSame('superadmin', $batch->source);
            $this->assertSame('course', $batch->kind);
            $this->assertSame(3, $batch->quantity);
            $this->assertSame($course->id, $batch->course_id);
            $this->assertSame([$option->id], $batch->option_product_ids);
            $this->assertNull($batch->tenant_id);
            $this->assertNull($batch->shop_id);
            $this->assertNull($batch->order_id);
            $this->assertNull($batch->request_fingerprint);
            // Zusatzprodukte gesammelt angehängt.
            $this->assertSame(3, DB::table('coupon_option_product')->whereIn('coupon_id', $coupons->pluck('id'))->where('product_id', $option->id)->count());
            $this->assertTrue($coupons->every(fn ($c) => preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $c->code) === 1));
        });
        $this->assertCount(3, session('generatedCodes'));
    }

    public function test_a_single_code_gets_no_batch_row_and_school_codes_stay_out_of_the_shop_table(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $single = $this->label();
        $this->actingAs($superadmin)->post('/superadmin/coupons', ['target' => 'course:'.$course->id, 'quantity' => 1, 'batch_label' => $single])
            ->assertSessionHas('status', 'Coupon-Code erzeugt.');
        $this->assertNull($this->onAdmin(fn () => Coupon::where('batch_label', $single)->value('batch_id')));

        // Paket für eine Schule: im Codebestand, aber nicht unter „Bestellungen aus dem Shop“.
        $label = $this->label();
        $this->actingAs($superadmin)->post('/superadmin/coupons', ['target' => 'course:'.$course->id, 'quantity' => 2, 'batch_label' => $label, 'tenant_id' => $tenant->id])
            ->assertSessionHasNoErrors();
        $batchId = $this->onAdmin(fn () => Coupon::where('batch_label', $label)->value('batch_id'));
        $this->assertSame($tenant->id, $this->onAdmin(fn () => CouponBatch::withoutGlobalScopes()->whereKey($batchId)->value('tenant_id')));
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons')->assertOk()->assertSee('Noch keine Bestellungen aus dem Shop.');
    }

    public function test_shop_batches_still_require_their_shop_fields(): void
    {
        $tenant = $this->createTestTenant();
        $this->expectException(QueryException::class);
        $this->onAdmin(fn () => CouponBatch::create(['tenant_id' => $tenant->id, 'kind' => 'course', 'quantity' => 1, 'source' => 'woocommerce', 'status' => 'delivered']));
    }

    public function test_a_code_collision_is_retried_with_fresh_codes(): void
    {
        $course = $this->existingCourse('SRC');
        $label = $this->label();
        $existing = $this->onAdmin(fn () => Coupon::create(['code' => Coupon::randomCode(), 'course_id' => $course->id, 'batch_label' => $label]));

        // Erster Block: einer der Codes gibt es schon, und die Vorprüfung sieht ihn
        // (wie bei einer gleichzeitigen Anlage) nicht -- die Datenbank lehnt ab,
        // nur dieser Block wird mit neuen Codes wiederholt.
        $factory = new class($existing->code) extends CouponBatchFactory
        {
            public int $checks = 0;

            private bool $collided = false;

            public function __construct(private string $taken) {}

            protected function existingCodes(array $codes): array
            {
                $this->checks++;

                return $this->checks === 1 ? [] : parent::existingCodes($codes);
            }

            protected function randomCode(): string
            {
                if (! $this->collided) {
                    $this->collided = true;

                    return $this->taken;
                }

                return parent::randomCode();
            }
        };

        $result = $this->onAdmin(fn () => DB::transaction(fn () => $factory->create(['kind' => 'course', 'course_id' => $course->id, 'batch_label' => $label], 4)));

        $this->assertCount(4, $result['codes']);
        $this->assertNotContains($existing->code, $result['codes']);
        $this->assertSame(2, $factory->checks, 'genau eine Wiederholung');
        $this->assertSame(5, $this->onAdmin(fn () => Coupon::where('batch_label', $label)->count()));
        $this->assertCount(4, array_unique($result['codes']));
    }

    public function test_large_quantities_are_inserted_in_chunks(): void
    {
        $course = $this->existingCourse('SRC');
        $label = $this->label();
        $quantity = CouponBatchFactory::CHUNK_SIZE + 7;

        $result = $this->onAdmin(fn () => app(CouponBatchFactory::class)->create(['kind' => 'renewal', 'course_id' => $course->id, 'batch_label' => $label], $quantity, ['source' => 'superadmin']));

        $this->assertCount($quantity, array_unique($result['codes']));
        $this->assertSame($quantity, $this->onAdmin(fn () => Coupon::where('batch_id', $result['batch']->id)->where('is_renewal', true)->count()));
        $this->assertSame('renewal', $result['batch']->kind);
    }
}
