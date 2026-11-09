<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Review-Runde 11: E-Mail-Adressen vor der Prüfung klein schreiben
 * (statt „muss in Kleinbuchstaben sein“ wie bisher bei Einzel- und
 * Team-Einladungen, während die Sammel-Einladung still umwandelte).
 */
class EmailInput
{
    public static function normalize(Request $request, string $key = 'email'): void
    {
        $value = $request->input($key);
        if (is_string($value)) {
            $request->merge([$key => mb_strtolower(trim($value))]);
        }
    }
}
