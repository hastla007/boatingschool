<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Coupon extends Model
{
    use HasUuids;

    protected $table = 'coupon';

    public $timestamps = false;

    protected $fillable = [
        'code', 'course_id', 'product_id', 'is_renewal', 'tenant_id', 'batch_label', 'created_by_user_id',
        'redeemed_by_user_id', 'redeemed_tenant_id', 'redeemed_at',
        'expires_at', 'voided_at', 'voided_by_user_id', 'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'is_renewal' => 'boolean',
            'redeemed_at' => 'datetime',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Zusätzlich gebündelte Produkte bei einem Kurs-Coupon (siehe redeemForCourse). */
    /** Code-Batch einer Shop-Bestellung (sonst null). */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    public function optionProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_option_product');
    }

    /** Anzeigename des durch diesen Coupon freigeschalteten Kurses oder Produkts. */
    public function redeemableName(): string
    {
        return $this->course?->name ?? $this->product?->name ?? '—';
    }

    public function isForProduct(): bool
    {
        return $this->product_id !== null;
    }

    /** Ein Verlängerungscode verlängert ein bestehendes Kurs-Entitlement statt ein neues anzulegen (siehe CouponRedemptionService::redeemForRenewal). */
    public function isForRenewal(): bool
    {
        return $this->is_renewal;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function redeemedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'redeemed_tenant_id');
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Code so finden, wie Lernende ihn eintippen: Groß-/Kleinschreibung egal,
     * Review-Runde 12: auch mit Leerzeichen oder ohne Bindestriche
     * (XXXX XXXX XXXX XXXX / XXXXXXXXXXXXXXXX). Die RLS-Policy blendet Codes
     * anderer Schulen aus. Liefert auch eingelöste/gesperrte Codes.
     */
    public static function lookup(string $entered): ?self
    {
        $code = mb_strtoupper(trim(self::normalizeInput($entered)));
        if ($code === '' || mb_strlen($code) > 100) {
            return null;
        }
        $coupon = static::where('code', $code)->first();
        $compact = preg_replace('/[\s\-]+/u', '', $code);
        if (! $coupon && preg_match('/^[A-Z0-9]{16}$/', $compact)) {
            $coupon = static::where('code', implode('-', str_split($compact, 4)))->first();
        }

        return $coupon;
    }

    /**
     * Aus PDFs, Mails oder Messengern kopierte Codes: Gedankenstriche u. Ä. als
     * Bindestrich, geschützte/schmale Leerzeichen als Leerzeichen, unsichtbare
     * Zeichen (Zero-Width, BOM) entfernen.
     */
    public static function normalizeInput(string $entered): string
    {
        $entered = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $entered) ?? $entered;
        $entered = preg_replace('/\p{Pd}/u', '-', $entered) ?? $entered;

        return preg_replace('/\p{Zs}/u', ' ', $entered) ?? $entered;
    }

    /** Noch einlösbar: nicht eingelöst, nicht gesperrt, nicht abgelaufen. */
    public function isUsable(): bool
    {
        return ! $this->isRedeemed() && ! $this->isVoided() && ! $this->isExpired();
    }

    /** offen | eingelöst | gesperrt | abgelaufen */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isRedeemed() => 'eingelöst',
            $this->isVoided() => 'gesperrt',
            $this->isExpired() => 'abgelaufen',
            default => 'offen',
        };
    }

    /** Nur einlösbare Codes. */
    public function scopeUsable($query)
    {
        return $query->whereNull('redeemed_at')->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * str_shuffle() (Sicherheitsreview M8) nutzt Mt19937, keinen
     * kryptographischen Zufall, und wählte außerdem je Gruppe nur 4 von 33
     * Zeichen ohne Wiederholung -- zusammen nur ~59 Bit Entropie. random_int()
     * ist CSPRNG-gestützt; vier Gruppen à vier Zeichen MIT Wiederholung aus
     * demselben 33-Zeichen-Alphabet (ohne I/O/0/1 zur besseren Lesbarkeit)
     * ergeben 16 Zeichen und rund 80 Bit Entropie.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = self::randomCode();
        } while (static::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Ein zufälliger Code ohne Prüfung auf Eindeutigkeit (die übernimmt der
     * Aufrufer, z. B. CouponBatchFactory gesammelt je Paket).
     */
    public static function randomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $alphabetLength = strlen($alphabet);

        $segments = [];
        for ($segment = 0; $segment < 4; $segment++) {
            $chars = '';
            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[random_int(0, $alphabetLength - 1)];
            }
            $segments[] = $chars;
        }

        return implode('-', $segments);
    }
}
