<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUser extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'tenant_user';

    protected array $compositeKey = ['tenant_id', 'user_id'];

    // Zusammengesetzter Primärschlüssel (tenant_id, user_id) laut schema.sql;
    // Eloquent unterstützt keine Composite-Keys nativ, daher werden
    // instanzgebundene find()/delete()-Aufrufe hier bewusst vermieden.
    public $incrementing = false;

    const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
