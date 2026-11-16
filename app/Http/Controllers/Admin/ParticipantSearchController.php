<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ParticipantFilters;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teilnehmersuche (JSON) für das Suchfeld „Zuweisen“ im Reiter
 * Zugangscodes (admin/_participant-picker.blade.php): statt einer Liste
 * aller Lernenden im HTML sucht das Feld hier nach Name oder
 * E-Mail-Adresse, höchstens SEARCH_LIMIT Treffer, erst ab zwei Zeichen.
 * Gesucht wird nur unter den Teilnehmern, denen die Schule auch einen Code
 * zuweisen kann (ParticipantFilters::assignableLearners(), dieselbe Grenze
 * wie Admin\CouponController::assign()); die Route ist wie die Zuweisung
 * Inhabern und Admins vorbehalten.
 */
class ParticipantSearchController extends Controller
{
    /** Höchstzahl der Treffer. */
    public const SEARCH_LIMIT = 20;

    public function __invoke(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }
        $like = '%'.addcslashes(mb_substr($search, 0, 100), '\\%_').'%';

        $users = ParticipantFilters::assignableLearners($tenantContext->tenant(), $request->user())
            ->where(fn (Builder $query) => $query->where('display_name', 'ilike', $like)->orWhere('email', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like]))
            ->orderByRaw('lower(coalesce(display_name, email))')->orderBy('id')
            ->limit(self::SEARCH_LIMIT)->get(['id', 'display_name', 'email']);

        return response()->json([
            'data' => $users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values(),
        ]);
    }
}
