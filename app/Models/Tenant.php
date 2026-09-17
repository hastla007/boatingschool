<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenant';

    public $timestamps = true;

    protected $fillable = [
        'slug', 'name', 'status', 'default_locale',
    ];

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class, 'tenant_id');
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    public function courseDefinitions(): HasMany
    {
        return $this->hasMany(CourseDefinition::class, 'tenant_id');
    }
}
