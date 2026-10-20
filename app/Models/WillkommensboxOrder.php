<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WillkommensboxOrder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'willkommensbox_order';

    protected $fillable = [
        'tenant_id', 'user_id', 'street', 'postal_code', 'city', 'country', 'note',
    ];
}
