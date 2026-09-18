<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $table = 'platform_setting';

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['site_name', 'support_email', 'maintenance_mode', 'maintenance_message'];

    protected function casts(): array
    {
        return ['maintenance_mode' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
