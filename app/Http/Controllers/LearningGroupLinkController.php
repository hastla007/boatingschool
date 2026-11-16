<?php

namespace App\Http\Controllers;

use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Support\OwnerConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Web-Ziel des Beitrittslinks einer Lerngruppe (…/gruppe/<code>/, siehe
 * LearningGroupService::payload() join_url), wenn die App ihn nicht als
 * Universal-/App-Link abfängt -- z. B. am Computer oder ohne installierte
 * App. Die Seite tritt NICHT bei (das geht nur in der App, angemeldet bei
 * der Schule der Gruppe), sondern erklärt den Weg dorthin.
 *
 * Gruppenname und Schule nur bei einem gültigen, aktiven Beitrittscode
 * (g…, gleiche Prüfung wie die Vorschau der App: LearningGroup::isActive(),
 * Schule aktiv); unbekannte, abgelaufene, beendete und persönliche
 * Einladungscodes (i…, gelten nur für eine Adresse) bekommen dieselbe
 * neutrale Seite mit gleichem Status -- kein Ausprobieren von Codes.
 * Gedrosselt wie die Vorschau der App (throttle in routes/web.php).
 */
class LearningGroupLinkController extends Controller
{
    public function show(Request $request, string $code): Response
    {
        $details = $this->activeGroup(strtolower($code), app()->bound('currentTenant') ? app('currentTenant') : null);

        return response()->view('learning-group-link.show', [
            'code' => strtolower($code),
            'groupName' => $details['group'] ?? null,
            'schoolName' => $details['school'] ?? null,
        ])->withHeaders([
            // Der Code ist ein Beitrittsschlüssel: nicht zwischenspeichern, nicht indexieren.
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Nur lesend über die Owner-Verbindung: die Seite läuft ohne
     * Schulkontext (zentrale Domain), learning_group steht unter RLS.
     *
     * @return ?array{group: string, school: string}
     */
    private function activeGroup(string $code, ?Tenant $hostTenant): ?array
    {
        if (preg_match('/^g[0-9a-f]{32}$/', $code) !== 1) {
            return null;
        }

        return OwnerConnection::run(function () use ($code, $hostTenant) {
            $group = LearningGroup::withoutGlobalScopes()->where('join_code', $code)->first();
            if (! $group || ! $group->isActive()) {
                return null;
            }
            $tenant = Tenant::find($group->tenant_id);
            // Auf der Adresse einer anderen Schule keine Angaben zu dieser Gruppe.
            if (! $tenant || in_array($tenant->status, ['closed', 'suspended'], true) || ($hostTenant && $hostTenant->id !== $tenant->id)) {
                return null;
            }

            return ['group' => (string) $group->name, 'school' => (string) $tenant->name];
        });
    }
}
