<?php

namespace App\Support;

/** Review-Runde 11: „2026-02-30“ oder „2026-13-45“ sind kein Datum (vorher 500). */
class DateInput
{
    public static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
