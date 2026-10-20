<?php

namespace App\Models\Concerns;

/**
 * Berechnet einen rabattierten VK aus einem frei eingegebenen price_label
 * (z. B. "299 €", "1.299,00 €") und discount_percent -- genutzt von allen
 * Bannern, die einen optionalen Rabatt zeigen (TenantAddon,
 * CoursePromoBanner). price_label bleibt bewusst ein Freitextfeld, daher
 * liefert diese Methode null, wenn sich keine Zahl extrahieren lässt oder
 * kein Rabatt hinterlegt ist.
 */
trait HasDiscountedPriceLabel
{
    public function discountedPriceLabel(): ?string
    {
        if (! $this->discount_percent || ! $this->price_label) {
            return null;
        }

        $amount = $this->parsePriceAmount($this->price_label);

        if ($amount === null) {
            return null;
        }

        $discounted = round($amount * (1 - $this->discount_percent / 100), 2);

        return number_format($discounted, 2, ',', '.').' €';
    }

    private function parsePriceAmount(string $label): ?float
    {
        if (! preg_match('/[\d.,]+/', $label, $matches)) {
            return null;
        }

        $number = $matches[0];

        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif (str_contains($number, ',')) {
            $number = str_replace(',', '.', $number);
        }

        return is_numeric($number) ? (float) $number : null;
    }
}
