<?php

namespace App\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Kennung je API-Anfrage für Support und Protokoll (Header X-Request-Id). */
class RequestId
{
    public static function current(Request $request): string
    {
        $id = $request->attributes->get('request_id');
        if (! is_string($id)) {
            $given = (string) $request->header('X-Request-Id', '');
            $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) ? $given : 'req_'.Str::ulid();
            $request->attributes->set('request_id', $id);
        }

        return $id;
    }
}
