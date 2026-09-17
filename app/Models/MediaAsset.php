<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    use HasUuids;

    protected $table = 'media_asset';

    protected $fillable = [
        'asset_key', 'media_type', 'storage_path', 'mime_type', 'alt_text',
        'source', 'rights_status', 'rights_owner', 'license_reference', 'status',
    ];
}
