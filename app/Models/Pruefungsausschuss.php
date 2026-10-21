<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fest definierte Prüfungsausschuss-Adresse (siehe DMYV-Liste), platt-
 * formweit gültig -- keine Tenant-Bindung, keine RLS, nur lesend für die
 * Bootsschule (siehe tenant_course_location.pruefungsausschuss_id).
 */
class Pruefungsausschuss extends Model
{
    use HasUuids;

    protected $table = 'pruefungsausschuss';

    /** "Kommission — Standorttyp" oder nur "Kommission", wenn kein Standorttyp gepflegt ist. */
    public function label(): string
    {
        return $this->standorttyp ? "{$this->kommission} — {$this->standorttyp}" : $this->kommission;
    }

    /** "Straße, PLZ Stadt" -- null, wenn keines der Adressfelder gepflegt ist. */
    public function adresse(): ?string
    {
        $plzStadt = trim(implode(' ', array_filter([$this->plz, $this->stadt])));
        $parts = array_filter([$this->strasse, $plzStadt]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
