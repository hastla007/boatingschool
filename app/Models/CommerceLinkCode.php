<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Kurzlebiger Einmalcode, mit dem ein Schuladmin sein Shopkonto an die Schule bindet (nur der Hash wird gespeichert). */
class CommerceLinkCode extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'commerce_link_code';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'created_by_user_id', 'code_hash', 'expires_at', 'used_at', 'used_by_account_id', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** XXXX-XXXX ohne verwechselbare Zeichen. */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s\-]+/', '', $code)));
    }
}
