<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Suche und Filter der Teilnehmerliste im Bootsschul-Admin (Liste und
 * CSV-Export nutzen dieselben Filter): Name oder E-Mail, Kurs, Status.
 */
class ParticipantFilters
{
    /** @return array{p_search: string, p_course: string, p_status: string} */
    public static function fromRequest(Request $request): array
    {
        $value = fn (string $key) => is_string($request->query($key)) ? trim($request->query($key)) : '';

        return [
            'p_search' => mb_substr($value('p_search'), 0, 100),
            'p_course' => Str::isUuid($value('p_course')) ? $value('p_course') : '',
            'p_status' => in_array($value('p_status'), ['active', 'suspended'], true) ? $value('p_status') : '',
        ];
    }

    /**
     * Kein Konto-Orakel bei Einladungen: ein von der Schule neu angelegtes,
     * noch nie aktiviertes Konto (app_user.status = invited) erscheint bis
     * zur Annahme nur unter „Offene Einladungen“ -- genau wie eine Einladung
     * an eine Adresse mit bestehendem Konto, die bis dahin gar keine
     * Mitgliedschaft hat. Sonst verriete die Teilnehmerliste (bzw. Zahl,
     * Export, Code-Auswahl), welche eingeladene Adresse schon ein Konto hatte.
     *
     * @param  Builder  $query  TenantUser-Abfrage der Schule
     */
    public static function withoutOpenInvitations(Builder $query): Builder
    {
        return $query->whereHas('user', fn ($u) => $u->where('status', '!=', 'invited'));
    }

    /** @param  Builder  $query  TenantUser-Abfrage der Schule */
    public static function apply(Builder $query, Tenant $tenant, array $filters): Builder
    {
        self::withoutOpenInvitations($query);
        if ($filters['p_search'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['p_search']).'%';
            $query->whereHas('user', fn ($u) => $u->where(fn ($q) => $q->where('display_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like])));
        }
        if ($filters['p_course'] !== '') {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('entitlement')
                ->whereColumn('entitlement.user_id', 'tenant_user.user_id')
                ->where('entitlement.tenant_id', $tenant->id)
                ->where('entitlement.course_id', $filters['p_course']));
        }
        if ($filters['p_status'] !== '') {
            $query->where('tenant_user.status', $filters['p_status']);
        }

        return $query;
    }

    public static function active(array $filters): bool
    {
        return implode('', $filters) !== '';
    }
}
