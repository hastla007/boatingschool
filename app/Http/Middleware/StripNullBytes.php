<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Review-Runde 11: ein Nullbyte in einer Eingabe führte bei JSON-Spalten
 * (u. a. dem Protokoll) zu einem Datenbankfehler (500) und kürzte Text-
 * spalten stillschweigend. Nullbytes haben in keiner Eingabe einen Sinn.
 */
class StripNullBytes extends TransformsRequest
{
    protected function transform($key, $value)
    {
        return is_string($value) ? str_replace("\0", '', $value) : $value;
    }
}
