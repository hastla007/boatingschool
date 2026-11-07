<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CourseProgressService;
use App\Services\LearnerOverview;
use App\Services\ParticipantInviter;
use App\Support\ParticipantFilters;
use App\Support\StatusLabels;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Teilnehmer: Sammel-Einladung aus einer Liste und CSV-Export (mit den Filtern der Liste). */
class ParticipantToolsController extends Controller
{
    private const MAX_BULK = 100;

    public function bulkInvite(Request $request, TenantContext $tenantContext, ParticipantInviter $inviter): RedirectResponse
    {
        $request->validate(['emails' => ['required', 'string', 'max:20000']]);
        $tenant = $tenantContext->tenant();

        $lines = collect(preg_split('/\R/u', $request->input('emails')))->map(fn ($l) => trim($l))->filter()->values();
        if ($lines->count() > self::MAX_BULK) {
            return back()->withErrors(['emails' => 'Höchstens '.self::MAX_BULK.' Zeilen auf einmal.'])->withInput();
        }

        $invited = 0;
        $mailFailed = 0;
        $skipped = [];
        $seen = [];
        $limitReached = false;
        foreach ($lines as $line) {
            [$name, $email] = $this->parseLine($line);
            if ($email === null || Validator::make(['email' => $email], ['email' => ['email', 'max:320']])->fails()) {
                $skipped[] = "„{$line}“ (keine gültige E-Mail-Adresse)";

                continue;
            }
            if (mb_strlen((string) $name) > 120) {
                $skipped[] = "{$email} (Name zu lang)";

                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $existing = User::on('pgsql_admin')->where('email', $email)->first();
            if ($existing && TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->exists()) {
                $skipped[] = "{$email} (bereits Teilnehmer)";

                continue;
            }
            if ($inviter->limitReached($tenant)) {
                $limitReached = true;
                break;
            }

            $result = $inviter->invite($tenant, $request->user(), $name ?: strstr($email, '@', true), $email);
            if ($result['duplicate'] ?? false) {
                $skipped[] = "{$email} (heute schon eingeladen)";

                continue;
            }
            $invited++;
            if (! $result['mail_sent']) {
                $mailFailed++;
            }
        }

        $status = $invited === 1 ? 'Eine Person eingeladen.' : "{$invited} Personen eingeladen.";
        if ($mailFailed > 0) {
            $status .= " Bei {$mailFailed} konnte die Einladungsmail nicht verschickt werden.";
        }
        if ($limitReached) {
            $status .= ' Tageslimit von '.ParticipantInviter::DAILY_LIMIT.' Einladungen erreicht, die übrigen bitte morgen einladen.';
        }
        if ($skipped !== []) {
            $status .= ' Übersprungen: '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? ' …' : '').'.';
        }

        return redirect()->route('admin.dashboard', ['tab' => 'participants'])->with('status', $status);
    }

    public function export(Request $request, TenantContext $tenantContext, LearnerOverview $overview): StreamedResponse
    {
        $tenant = $tenantContext->tenant();
        $filters = ParticipantFilters::fromRequest($request);
        $members = ParticipantFilters::apply(
            TenantUser::with('user')->where('tenant_id', $tenant->id)->where('role', 'learner'), $tenant, $filters
        )->orderBy('created_at')->orderBy('user_id')->get()->filter(fn ($m) => $m->user);

        $userIds = $members->pluck('user_id');
        $last = $overview->lastActivities($tenant, $userIds);
        $entitlementRows = Entitlement::where('tenant_id', $tenant->id)->whereIn('user_id', $userIds)->with('course')->get();
        // Fortschritt je Kurs in einem Schritt (statt zwei Abfragen je Zeile).
        $percents = $entitlementRows->filter(fn ($e) => $e->course)->groupBy('course_id')
            ->map(fn ($group) => app(CourseProgressService::class)->overallPercents($group->first()->course, $tenant, $group->pluck('user_id')));
        $entitlements = $entitlementRows->groupBy('user_id');

        AuditLog::record($tenant->id, $request->user()->id, 'participants.export', 'tenant_user', null, null, ['count' => $members->count(), 'filters' => array_filter($filters)]);

        return response()->streamDownload(function () use ($members, $last, $entitlements, $percents, $overview, $tenant) {
            $out = fopen('php://output', 'w');
            // Formel-Injektion verhindern: von Lernenden gepflegte Texte wie
            // „=HYPERLINK(…)“ würde Excel sonst als Formel ausführen.
            // Review-Runde 9: ohne Escape-Zeichen (RFC 4180 wie Excel) -- mit
            // '\\' ließ sich per \" eine eigene Zelle mit Formel einschleusen;
            // die Prüfung sieht auch hinter führende Leerzeichen/Anführungszeichen.
            $put = fn (array $row) => fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[\s"\']*[=+\-@\t\r]/u', $v) ? "'".$v : $v, $row), ';', '"', '');
            fwrite($out, "\xEF\xBB\xBF");
            $put(['Vorname', 'Nachname', 'E-Mail', 'Status', 'Teilnehmer seit', 'Zuletzt gelernt', 'Kurs', 'Zugang', 'Zugang bis', 'Fortschritt %', 'Prüfungsreif']);
            foreach ($members as $m) {
                $base = [
                    // Ohne gepflegten Vor-/Nachnamen den Anzeigenamen aufteilen (wie im Profil).
                    ...array_values($m->user->first_name || $m->user->last_name
                        ? ['first_name' => $m->user->first_name ?? '', 'last_name' => $m->user->last_name ?? '']
                        : array_map(fn ($v) => $v ?? '', User::splitDisplayName((string) $m->user->name))),
                    $m->user->email,
                    StatusLabels::userStatus($m->status), $m->created_at?->timezone('Europe/Berlin')->format('d.m.Y') ?? '',
                    isset($last[$m->user_id]) ? Carbon::parse($last[$m->user_id])->timezone('Europe/Berlin')->format('d.m.Y') : '',
                ];
                $rows = $entitlements->get($m->user_id, collect())->filter(fn ($e) => $e->course);
                if ($rows->isEmpty()) {
                    $put(array_merge($base, ['', '', '', '', '']));
                }
                foreach ($rows as $e) {
                    $percent = $percents[$e->course_id][$m->user_id] ?? 0;
                    $put(array_merge($base, [
                        $e->course->name, StatusLabels::entitlementStatus($e->status), $e->valid_until?->timezone('Europe/Berlin')->format('d.m.Y') ?? '',
                        $percent, $percent >= $overview->threshold($tenant, $e->course) ? 'ja' : 'nein',
                    ]));
                }
            }
            fclose($out);
        }, 'teilnehmer-'.$tenant->slug.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "Max Muster, max@x.de" | "Max Muster <max@x.de>" | "max@x.de" | "max@x.de; Max Muster" */
    private function parseLine(string $line): array
    {
        if (preg_match('/^(.*?)<\s*([^>\s]+@[^>\s]+)\s*>\s*$/u', $line, $m)) {
            return [trim($m[1], " ,;\"'"), mb_strtolower($m[2])];
        }
        $parts = array_map('trim', preg_split('/[,;\t]/u', $line));
        $email = collect($parts)->first(fn ($p) => str_contains($p, '@'));
        $name = collect($parts)->reject(fn ($p) => $p === $email)->implode(' ');

        return [trim($name), $email ? mb_strtolower($email) : null];
    }
}
