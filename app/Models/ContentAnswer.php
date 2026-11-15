<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContentAnswer extends Model
{
    use HasUuids;

    protected $table = 'content_answer';

    public $timestamps = false;

    protected $fillable = ['revision_id', 'answer_key', 'answer_text', 'is_correct', 'sort_order'];

    /**
     * SEC-A1 (Verteidigung in der Tiefe): zufällige UUIDv4 statt der
     * zeitlich sortierbaren UUIDv7 -- sonst verrät die Anlagereihenfolge
     * (A zuerst, bei amtlichen Katalogen die richtige) die Lösung. Nach
     * außen gehen ohnehin nur Kennungen je Person (AnswerToken).
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContentQuestionRevision::class, 'revision_id');
    }
}
