<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisTask extends Model
{
    use HasUuids;

    protected $table = 'praxis_task';

    public $timestamps = false;

    protected $fillable = [
        'course_id', 'content_id', 'kategorie', 'unterkategorie', 'pruefungsbezug',
        'aufgabentyp', 'frage', 'antwort', 'erklaerung', 'media_asset_id',
        'quelle', 'quellen_url', 'sort_order',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
