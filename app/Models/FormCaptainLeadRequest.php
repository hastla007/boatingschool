<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotenz des Lead-Eingangs (Vertrag A.4): Schlüssel, Body-Hash und
 * Ergebnis, mindestens 30 Tage -- auch nach dem Löschen des Leads. Keine
 * Klartextdaten.
 */
class FormCaptainLeadRequest extends Model
{
    use HasUuids;

    protected $table = 'form_captain_lead_request';

    public $timestamps = false;

    protected $fillable = ['key_id', 'idempotency_key', 'body_hash', 'lead_public_id', 'http_status', 'code', 'created_at'];

    protected function casts(): array
    {
        return ['http_status' => 'integer', 'created_at' => 'datetime'];
    }
}
