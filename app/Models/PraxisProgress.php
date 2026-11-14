<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisProgress extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    /** Stände der App-Karteikarten; known entspricht completed im Web. */
    public const STATUS_OPEN = 'open';

    public const STATUS_PRACTICE = 'practice';

    public const STATUS_KNOWN = 'known';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PRACTICE, self::STATUS_KNOWN];

    protected $table = 'praxis_progress';

    protected array $compositeKey = ['tenant_id', 'user_id', 'praxis_task_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'praxis_task_id', 'completed', 'status', 'updated_at'];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PraxisTask::class, 'praxis_task_id');
    }
}
