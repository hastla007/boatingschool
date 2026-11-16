<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ausgang an den BoatingSchool Workflow Connector (docs/workflow/README.md).
 *
 * Status: pending (wartet) -> sending (beansprucht, claimed_at) -> done | pending (nächster Versuch) | failed.
 * Die id ist die stabile Event-ID (App\Workflow\WorkflowOutbox::eventId()).
 */
class WorkflowEvent extends Model
{
    protected $table = 'workflow_event';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'type', 'version', 'scope', 'tenant_ref', 'subject_type', 'subject_id', 'correlation_id', 'payload',
        'status', 'attempts', 'last_error', 'occurred_at', 'available_at', 'claimed_at', 'delivered_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'version' => 'integer', 'attempts' => 'integer',
            'occurred_at' => 'datetime', 'available_at' => 'datetime', 'claimed_at' => 'datetime',
            'delivered_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }

    /** Umschlag, wie er an den Connector geht (ohne Signatur). */
    public function envelope(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'scope' => $this->scope,
            'tenant_id' => $this->tenant_ref,
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id],
            'correlation_id' => $this->correlation_id,
            'data' => (object) ($this->payload ?? []),
        ];
    }
}
