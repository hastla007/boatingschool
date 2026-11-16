<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lead aus einem Form-Captain-Formular der Website (BS-06,
 * docs/integrations/form-captain-leads.md): ein unqualifizierter Interessent.
 * Keinem Tenant zugeordnet; der Eingang legt nie Benutzer, Schule, Shopkonto
 * oder Codes an. Plattformweit, nur über die Owner-Verbindung (Superadmin,
 * signierter Endpunkt).
 */
class FormCaptainLead extends Model
{
    use HasUuids;

    public const NEW = 'new';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    /** Status => Anzeige im Superadmin. */
    public const STATUS_LABELS = [
        self::NEW => 'Neu',
        self::IN_PROGRESS => 'In Bearbeitung',
        self::DONE => 'Erledigt',
        self::REJECTED => 'Abgelehnt',
    ];

    /** Zwecke (Entscheidung des Inhabers vom 16.11.2026). */
    public const PURPOSE_LABELS = [
        'support' => 'Support',
        'demo' => 'Demo',
        'consulting' => 'Beratung',
        'school_access' => 'Schulzugang',
        'pricing' => 'Preisanfrage',
    ];

    protected $table = 'form_captain_lead';

    protected $fillable = [
        'public_id', 'status', 'key_id', 'site_url', 'page_url', 'form_id', 'entry_id', 'plugin_version',
        'purpose', 'contact_name', 'contact_email', 'email_hash', 'contact_phone', 'contact_organisation', 'contact_role',
        'school_size', 'message', 'product_id', 'product_name', 'product_sku', 'customer_ref_id', 'customer_email', 'consent_given_at', 'consent_text_revision', 'consent_text_hash', 'submitted_at',
        'merged_count', 'last_merged_at', 'notes', 'rejection_reason', 'handled_by_user_id', 'status_changed_at', 'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'product_id' => 'integer',
            'customer_ref_id' => 'integer',
            'entry_id' => 'integer',
            'consent_given_at' => 'datetime',
            'consent_text_revision' => 'integer',
            'submitted_at' => 'datetime',
            'merged_count' => 'integer',
            'last_merged_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /** Pseudonymer Schlüssel der Absenderadresse (Tagesgrenze je Adresse, A.6). */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /** Ende der Aufbewahrung ab jetzt (nach Eingang bzw. Statusänderung). */
    public static function retainUntil(): Carbon
    {
        return now()->addDays(max(1, (int) config('form_captain.retention.days', 180)));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
