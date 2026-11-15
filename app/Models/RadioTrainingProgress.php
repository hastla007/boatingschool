<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Fortschritt im Funk-Training, siehe Migration 000479 und App\Support\FunkTraining. */
class RadioTrainingProgress extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    public const TYPE_PRAXIS = 'praxis';

    public const TYPE_TEXT = 'text';

    public const TYPE_VOCAB = 'vocab';

    /** Gerätesimulation: item_key "<gerät>:<lektion>", answers.level = beste Stufe. */
    public const TYPE_DEVICE = 'geraet';

    /** So oft in Folge gewusst, gilt eine Vokabel als gelernt. */
    public const VOCAB_STREAK_TO_LEARN = 3;

    protected $table = 'radio_training_progress';

    protected array $compositeKey = ['tenant_id', 'user_id', 'item_type', 'item_key'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'item_type', 'item_key', 'completed', 'streak', 'answers', 'updated_at'];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'streak' => 'integer',
            'answers' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Stand einer Person zu den angegebenen Einträgen eines Typs (Web und App).
     *
     * @param  list<string>|null  $keys  null = alle Einträge des Typs
     * @return Collection<string, self>
     */
    public static function forItems(string $tenantId, string $userId, string $type, ?array $keys = null): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return self::where('tenant_id', $tenantId)->where('user_id', $userId)->where('item_type', $type)
            ->when($keys !== null, fn ($q) => $q->whereIn('item_key', $keys))
            ->get()->keyBy('item_key');
    }

    public static function record(string $tenantId, string $userId, string $type, string $key, array $values): self
    {
        return self::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'item_type' => $type, 'item_key' => $key],
            $values + ['updated_at' => now()]
        );
    }
}
