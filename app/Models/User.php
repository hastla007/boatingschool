<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $table = 'app_user';

    protected $fillable = [
        'display_name',
        'name',
        'email',
        'password',
        'locale',
        'status',
        'external_identity',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Laravel Breeze/Auth-Views und -Requests arbeiten mit `name`; die
     * Spalte heißt laut schema.sql aber `display_name`.
     */
    public function getNameAttribute(): string
    {
        return $this->display_name ?? $this->email;
    }

    public function setNameAttribute(string $value): void
    {
        $this->attributes['display_name'] = $value;
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'user_id');
    }

    public function roleForTenant(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        return $this->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->value('role');
    }

    public function isAdminFor(?Tenant $tenant): bool
    {
        return in_array($this->roleForTenant($tenant), ['owner', 'admin'], true);
    }
}
