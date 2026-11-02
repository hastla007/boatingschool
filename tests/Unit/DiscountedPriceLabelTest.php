<?php

namespace Tests\Unit;

use App\Models\CoursePromoBanner;
use Tests\TestCase;

/** Review-Runde 3: Rabattpreis bei deutschem Tausenderformat. */
class DiscountedPriceLabelTest extends TestCase
{
    private function label(string $price, int $pct): ?string
    {
        $b = new CoursePromoBanner;
        $b->price_label = $price;
        $b->discount_percent = $pct;

        return $b->discountedPriceLabel();
    }

    public function test_german_thousands_separator_without_decimals(): void
    {
        $this->assertSame('1.039,20 €', $this->label('1.299 €', 20));
        $this->assertSame('1.039,20 €', $this->label('1.299,00 €', 20));
        $this->assertSame('239,20 €', $this->label('inkl. MwSt. 299 €', 20));
        $this->assertSame('79,20 €', $this->label('99 €', 20));
        // Punkt mit zwei Nachkommastellen bleibt Dezimalpunkt.
        $this->assertSame('7,99 €', $this->label('9.99 €', 20));
    }
}
