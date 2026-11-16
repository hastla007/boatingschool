<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'entitlement';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'valid_from', 'valid_until',
        'status', 'source_type', 'source_reference', 'expiry_reminded_at', 'capabilities', 'import_meta',
    ];

    /** So viele Tage vor dem Ablauf warnen Kursübersicht und Erinnerungsmail. */
    public const EXPIRY_WARNING_DAYS = 14;

    protected static function booted(): void
    {
        // Neues Ablaufdatum (Verlängerung): wieder an den Ablauf erinnern.
        static::saving(function (self $entitlement) {
            if ($entitlement->isDirty('valid_until') && ! $entitlement->isDirty('expiry_reminded_at')) {
                $entitlement->expiry_reminded_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            // null = voller Kurszugang, sonst Liste einzelner Funktionen (CourseAccess::ENTITLEMENT_CAPABILITIES).
            'capabilities' => 'array',
            // Herkunft eines WP-Imports (source, group_id, order_id, status), sonst null.
            'import_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Aktiv und läuft innerhalb von EXPIRY_WARNING_DAYS ab. */
    public function expiresSoon(): bool
    {
        return $this->isCurrentlyActive() && $this->valid_until !== null
            && $this->valid_until <= now()->addDays(self::EXPIRY_WARNING_DAYS);
    }

    /**
     * Zugang über eine Lerngruppe -- eine der Plattform (source_type „group“)
     * oder eine aus WordPress übernommene (import_meta.source „group“, ohne
     * Lerngruppe hier). Verlängert wird über die Schule, nicht per Code/Kauf.
     */
    public function isGroupAccess(): bool
    {
        return $this->source_type === 'group' || $this->isImportedGroupAccess();
    }

    /** Aus WordPress übernommener Gruppenplatz (siehe ImportMobileEntitlements). */
    public function isImportedGroupAccess(): bool
    {
        return $this->source_type === 'wordpress' && ($this->import_meta['source'] ?? null) === 'group';
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();

        return $this->valid_from <= $now && ($this->valid_until === null || $this->valid_until > $now);
    }

    /**
     * QA-L-1/QA-L-2: Zeilen, die ein Kurs- oder Verlängerungscode bzw. das
     * Freischalten durch die Schule reaktivieren oder verlängern darf --
     * nur volle Kurszugänge (capabilities = null), keine Gruppenzugänge
     * (die gehören zur Lerngruppe und enden mit ihr). Sonst entsteht eine
     * neue Vollzeile.
     */
    public function scopeOwnFullAccess(Builder $query): Builder
    {
        return $query->whereNull('capabilities')->where('source_type', '<>', 'group');
    }

    /**
     * Bug-Review B15: status wechselt nie automatisch auf "expired" (dafür
     * gibt es einen eigenen Scheduler-Befehl, siehe ExpireEntitlements),
     * daher reicht ein reines status=active für "aktiv" nicht aus -- diese
     * Scope prüft zusätzlich valid_from/valid_until, genauso wie
     * EntitlementService::activeEntitlements() und isCurrentlyActive().
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', 'active')
            ->where('valid_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now));
    }
}
