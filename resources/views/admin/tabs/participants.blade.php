@if ($canManageTenant)
    {{-- Team der Bootsschule: Ausbilder/Admins einladen, Rollen ändern, Inhaberschaft übergeben (TeamController). --}}
    @php($isOwner = auth()->user()->roleForTenant($currentTenant) === 'owner')
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1 flex items-center gap-1.5">
            <x-icon name="users" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Team
        </h3>
        <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">
            Ausbilder sehen Teilnehmer und deren Lernstand, können aber keine Kurse freischalten und kein Branding ändern.
            Admins können alles außer Admins verwalten. {{ $isOwner ? 'Als Inhaber können Sie Admins ernennen und die Inhaberschaft übergeben.' : 'Admins ernennt der Inhaber.' }}
        </p>
        <x-input-error :messages="$errors->get('team')" class="mb-2" />

        <div class="overflow-x-auto">
            <table class="w-full text-sm mb-4">
                <thead class="text-slate-500 dark:text-slate-300 text-left">
                    <tr><th class="py-1 pr-4">Name</th><th class="py-1 pr-4">E-Mail</th><th class="py-1 pr-4">Rolle</th><th class="py-1"></th></tr>
                </thead>
                <tbody>
                    @foreach ($team as $member)
                        @php($editable = $member->user_id !== auth()->id() && in_array($member->role, ['admin', 'instructor'], true) && ($isOwner || $member->role === 'instructor'))
                        <tr class="border-t border-slate-100 dark:border-slate-700">
                            <td class="py-2 pr-4 text-slate-700 dark:text-slate-200">
                                {{ $member->user->name }}{{ $member->user_id === auth()->id() ? ' (Sie)' : '' }}
                                @if ($member->user->hasTwoFactor())
                                    <span class="ml-1 text-xs rounded-full px-1.5 py-0.5 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200" title="Zwei-Faktor-Anmeldung aktiv">2FA</span>
                                @else
                                    <span class="ml-1 text-xs rounded-full px-1.5 py-0.5 {{ $currentTenant->branding?->require_team_two_factor ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}" title="Zwei-Faktor-Anmeldung nicht eingerichtet">ohne 2FA</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-slate-500 dark:text-slate-400">{{ $member->user->email }}</td>
                            <td class="py-2 pr-4">
                                @if ($editable)
                                    <form method="POST" action="{{ route('admin.team.role.update', $member->user_id) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PATCH')
                                        <select name="role" aria-label="Rolle von {{ $member->user->name }}" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                            @foreach ($isOwner ? ['instructor', 'admin', 'learner'] : ['instructor', 'learner'] as $roleOption)
                                                <option value="{{ $roleOption }}" @selected($member->role === $roleOption)>{{ \App\Support\StatusLabels::tenantRole($roleOption) }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs">Ändern</button>
                                    </form>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs">{{ \App\Support\StatusLabels::tenantRole($member->role) }}</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($member->role === 'instructor')
                                    @php($assigned = $instructorCourses->get($member->user_id, collect()))
                                    <details class="text-xs">
                                        <summary class="cursor-pointer text-slate-600 dark:text-slate-300">{{ $assigned->isEmpty() ? 'Alle Kurse' : $assigned->count().' '.($assigned->count() === 1 ? 'Kurs' : 'Kurse') }}</summary>
                                        <form method="POST" action="{{ route('admin.team.courses.update', $member->user_id) }}" class="mt-2 space-y-1">
                                            @csrf
                                            @method('PATCH')
                                            @foreach ($courses->whereNotIn('id', $disabledCourseIds) as $course)
                                                <label class="flex items-center gap-1.5"><input type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked($assigned->contains($course->id)) class="rounded border-slate-300"> {{ $course->name }}</label>
                                            @endforeach
                                            <p class="text-slate-600 dark:text-slate-400">Keine Auswahl: sieht alle Teilnehmer.</p>
                                            <button type="submit" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600">Speichern</button>
                                        </form>
                                    </details>
                                @endif
                                @if ($isOwner && $member->role === 'admin' && $member->status === 'active')
                                    <form method="POST" action="{{ route('admin.team.transfer-ownership', $member->user_id) }}" x-data="{}"
                                          @submit="if (! confirm('Inhaberschaft wirklich an {{ addslashes($member->user->name) }} übergeben? Sie werden danach Admin.')) $event.preventDefault()">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 rounded-lg bg-amber-50 text-amber-800 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-100 transition text-xs whitespace-nowrap">Inhaberschaft übergeben</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($pendingTeamInvitations as $invitation)
                        <tr class="border-t border-slate-100 dark:border-slate-700 text-slate-500 dark:text-slate-400">
                            <td class="py-2 pr-4 italic">eingeladen</td>
                            <td class="py-2 pr-4">{{ $invitation->email }}</td>
                            <td class="py-2 pr-4">{{ \App\Support\StatusLabels::tenantRole($invitation->role) }}</td>
                            <td class="py-2 text-xs">wartet auf Bestätigung</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mb-4 rounded-xl border border-slate-100 dark:border-slate-700 p-3 text-sm">
            @php($twoFactorRequired = (bool) $currentTenant->branding?->require_team_two_factor)
            @php($withoutTwoFactor = $team->reject(fn ($m) => $m->user->hasTwoFactor())->count())
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-slate-700 dark:text-slate-200">
                    <strong>Zwei-Faktor-Anmeldung fürs Team:</strong> {{ $twoFactorRequired ? 'Pflicht' : 'freiwillig' }}
                    <span class="text-xs text-slate-600 dark:text-slate-400">({{ $withoutTwoFactor }} von {{ $team->count() }} ohne 2FA)</span>
                </span>
                @if ($isOwner)
                    <form method="POST" action="{{ route('admin.team.two-factor-policy') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="require_team_two_factor" value="{{ $twoFactorRequired ? '0' : '1' }}">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-xs">{{ $twoFactorRequired ? 'Pflicht aufheben' : 'Zur Pflicht machen' }}</button>
                    </form>
                @endif
            </div>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Ist sie Pflicht, werden Team-Mitglieder ohne 2FA nach der Anmeldung zur Einrichtung geleitet und können den Admin-Bereich erst danach nutzen.</p>
            <x-input-error :messages="$errors->get('two_factor')" class="mt-1" />
        </div>

        <form method="POST" action="{{ route('admin.team.invite') }}" class="flex flex-col sm:flex-row gap-2">
            @csrf
            <label for="team_name" class="sr-only">Name</label>
            <input type="text" id="team_name" name="name" placeholder="Name" required value="{{ old('name') }}" class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <label for="team_email" class="sr-only">E-Mail</label>
            <input type="email" id="team_email" name="email" placeholder="E-Mail" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <label for="team_role" class="sr-only">Rolle</label>
            <select id="team_role" name="role" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <option value="instructor">{{ \App\Support\StatusLabels::tenantRole('instructor') }}</option>
                @if ($isOwner)
                    <option value="admin">{{ \App\Support\StatusLabels::tenantRole('admin') }}</option>
                @endif
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Ins Team einladen</button>
        </form>
        <x-input-error :messages="$errors->get('team_email')" class="mt-2" />
        <x-input-error :messages="$errors->get('role')" class="mt-2" />
    </div>
@endif

@if ($canInvite ?? true)
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
    <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
        <x-icon name="envelope" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Teilnehmer einladen
    </h3>
    <form method="POST" action="{{ route('admin.participants.invite') }}" class="space-y-2">
        @csrf
        <div class="flex flex-col sm:flex-row gap-2">
            <label for="invite_name" class="sr-only">Name</label>
            <input type="text" id="invite_name" name="name" placeholder="Name" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <label for="invite_email" class="sr-only">E-Mail</label>
            <input type="email" id="invite_email" name="email" placeholder="E-Mail" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div x-data="{ showAddress: false }">
            <button type="button" @click="showAddress = ! showAddress" class="text-xs text-slate-500 dark:text-slate-400 hover:underline">
                <span x-show="! showAddress">+ Adresse hinterlegen (optional)</span>
                <span x-show="showAddress" style="display: none">&minus; Adresse ausblenden</span>
            </button>
            <div x-show="showAddress" style="display: none" class="mt-2 grid sm:grid-cols-[2fr_1fr] gap-2">
                <label for="invite_street" class="sr-only">Straße &amp; Hausnummer</label>
                <input type="text" id="invite_street" name="street" placeholder="Straße & Hausnummer" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <label for="invite_postal_code" class="sr-only">PLZ</label>
                <input type="text" id="invite_postal_code" name="postal_code" placeholder="PLZ" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <label for="invite_city" class="sr-only">Wohnort</label>
                <input type="text" id="invite_city" name="city" placeholder="Wohnort" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <label for="invite_country" class="sr-only">Land</label>
                <select id="invite_country" name="country" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Land wählen&hellip;</option>
                    @foreach (\App\Support\Countries::OPTIONS as $countryOption)
                        <option value="{{ $countryOption }}">{{ $countryOption }}</option>
                    @endforeach
                </select>
            </div>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                Das Geburtsdatum muss der Teilnehmer für die Anmeldung zu Praxis &amp; Prüfung selbst im eigenen Profil angeben.
            </p>
        </div>
        @if ($canManageTenant)
        <div x-data="{ showCode: {{ $errors->has('code') ? 'true' : 'false' }} }">
            <button type="button" @click="showCode = ! showCode" class="text-xs text-slate-500 dark:text-slate-400 hover:underline">
                <span x-show="! showCode" @if ($errors->has('code')) style="display: none" @endif>+ Code zuweisen (optional)</span>
                <span x-show="showCode" @unless ($errors->has('code')) style="display: none" @endunless>&minus; Code ausblenden</span>
            </button>
            <div x-show="showCode" @unless ($errors->has('code')) style="display: none" @endunless class="mt-2">
                <label for="invite_code" class="sr-only">Coupon-Code</label>
                <input type="text" id="invite_code" name="code" value="{{ old('code') }}" placeholder="XXXX-XXXX-XXXX-XXXX" class="w-full sm:w-64 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">
                <x-input-error :messages="$errors->get('code')" class="mt-1" />
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                    Ein eigener Code (Kurs oder Produkt) wird dem Teilnehmer direkt bei der Einladung zugewiesen und eingelöst.
                </p>
            </div>
        </div>
        @endif
        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Einladen</button>
    </form>
</div>
@endif

@if ($canManageTenant)
<details class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6" @if ($errors->has('emails')) open @endif>
    <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Mehrere Teilnehmer auf einmal einladen</summary>
    <form method="POST" action="{{ route('admin.participants.bulk-invite') }}" class="mt-3 space-y-2">
        @csrf
        <label for="bulk_emails" class="block text-sm text-slate-600 dark:text-slate-300">Eine Person pro Zeile: <span class="font-mono text-xs">Vorname Nachname, name@example.de</span> oder nur die E-Mail-Adresse (höchstens 100 Zeilen).</label>
        <textarea id="bulk_emails" name="emails" rows="6" required class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono" placeholder="Max Mustermann, max@example.de&#10;erika@example.de">{{ old('emails') }}</textarea>
        <x-input-error :messages="$errors->get('emails')" class="mt-1" />
        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Alle einladen</button>
    </form>
</details>
@endif

<form method="GET" action="{{ route('admin.dashboard') }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-3 flex flex-wrap items-end gap-2">
    <input type="hidden" name="tab" value="participants">
    <div class="flex-1 min-w-48">
        <label for="p_search" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Name oder E-Mail</label>
        <input type="search" id="p_search" name="p_search" value="{{ $participantFilters['p_search'] }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
    </div>
    <div>
        <label for="p_course" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Kurs</label>
        <select id="p_course" name="p_course" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle Kurse</option>
            @foreach ($courses->whereNotIn('id', $disabledCourseIds) as $course)
                <option value="{{ $course->id }}" @selected($participantFilters['p_course'] === $course->id)>{{ $course->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="p_status" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Status</label>
        <select id="p_status" name="p_status" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle</option>
            <option value="active" @selected($participantFilters['p_status'] === 'active')>aktiv</option>
            <option value="suspended" @selected($participantFilters['p_status'] === 'suspended')>gesperrt</option>
        </select>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Filtern</button>
    @if (\App\Support\ParticipantFilters::active($participantFilters))
        <a href="{{ route('admin.dashboard', ['tab' => 'participants']) }}" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm">Zurücksetzen</a>
    @endif
    <div class="w-full sm:w-auto sm:ml-auto flex flex-wrap gap-3 text-sm">
        <a href="{{ route('admin.inactive.index') }}" class="underline text-slate-700 dark:text-slate-200 hover:no-underline">Inaktive Teilnehmer</a>
        @if ($canManageTenant)
            <a href="{{ route('admin.renewals.index') }}" class="underline text-slate-700 dark:text-slate-200 hover:no-underline">Kurszugänge verlängern</a>
            <a href="{{ route('admin.participants.export', array_filter($participantFilters)) }}" class="underline text-slate-700 dark:text-slate-200 hover:no-underline">Als CSV exportieren</a>
        @endif
    </div>
</form>
<p class="text-xs text-slate-600 dark:text-slate-400 mb-2">{{ $participants->total() }} {{ $participants->total() === 1 ? 'Teilnehmer' : 'Teilnehmer' }}{{ \App\Support\ParticipantFilters::active($participantFilters) ? ' gefunden' : '' }}</p>

{{-- Unter sm (Handy) wird jede Tabellenzeile per CSS zur Karte (Name, E-Mail,
     Kurse als umbrechende Badges, Status, Aktionen); ab sm die gewohnte Tabelle.
     Bewusst dasselbe Markup statt einer zweiten Kartenliste: jedes Formular
     gibt es nur einmal. --}}
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm block sm:table">
        <thead class="hidden sm:table-header-group bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">E-Mail</th>
                <th class="px-4 py-2">Kurse</th>
                <th class="px-4 py-2">Produkte</th>
                @if ($canManageTenant)
                <th class="px-4 py-2">
                    Kurs freischalten
                    <span class="block text-[11px] font-normal text-slate-500 dark:text-slate-400">verbraucht einen eigenen Code</span>
                </th>
                @endif
                @if ($canManageTenant)
                    <th class="px-4 py-2">Status</th>
                @endif
            </tr>
        </thead>
        <tbody class="block sm:table-row-group">
            @foreach ($participants as $membership)
                <tr class="block sm:table-row border-t first:border-t-0 sm:first:border-t border-slate-100 dark:border-slate-700 px-4 py-3 sm:p-0">
                    <td class="block sm:table-cell sm:px-4 sm:py-2">
                        <a href="{{ route('admin.participants.show', $membership->user_id) }}" class="flex items-center gap-2 hover:underline">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
                                {{-- Bug-Review B28: mb_substr/mb_strtoupper statt
                                     substr/strtoupper, sonst zerstört ein mit
                                     einem Umlaut beginnender Name das erste
                                     Zeichen (nur das erste Byte der
                                     UTF-8-Sequenz wird abgeschnitten). --}}
                                {{ mb_strtoupper(mb_substr($membership->user->name, 0, 1)) }}
                            </div>
                            <span class="text-slate-700 dark:text-slate-200">{{ $membership->user->name }}</span>
                        </a>
                        @if ($membership->user->status === 'invited')
                            {{-- Noch nie angemeldet: Link zum Passwort-Festlegen erneut schicken. --}}
                            <form method="POST" action="{{ route('admin.participants.invite') }}" class="mt-1 ml-9 flex flex-wrap items-center gap-2 text-xs">
                                @csrf
                                <input type="hidden" name="name" value="{{ $membership->user->name }}">
                                <input type="hidden" name="email" value="{{ $membership->user->email }}">
                                <span class="rounded-full px-2 py-0.5 bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">eingeladen, noch nicht angemeldet</span>
                                <button type="submit" class="underline text-slate-600 dark:text-slate-300">Einladung erneut senden</button>
                            </form>
                        @endif
                    </td>
                    <td class="block sm:table-cell pl-9 sm:px-4 sm:py-2 text-slate-500 dark:text-slate-400 break-all sm:break-normal">{{ $membership->user->email }}</td>
                    <td class="block sm:table-cell pt-2 sm:px-4 sm:py-2 text-slate-500 dark:text-slate-400">
                        <span class="sm:hidden block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Kurse</span>
                        @forelse ($membership->user->entitlements as $entitlement)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs mb-1">
                                {{ $entitlement->course->name }} ({{ \App\Support\StatusLabels::entitlementStatus($entitlement->status) }})
                            </span>
                        @empty
                            <span class="text-slate-600 dark:text-slate-400">keine</span>
                        @endforelse
                    </td>
                    <td class="block sm:table-cell pt-1 sm:px-4 sm:py-2 text-slate-500 dark:text-slate-400">
                        <span class="sm:hidden block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Produkte</span>
                        @forelse ($productPurchases->get($membership->user_id, collect()) as $purchase)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-xs mb-1">
                                {{ $purchase->product->name }}
                            </span>
                        @empty
                            <span class="text-slate-600 dark:text-slate-400">keine</span>
                        @endforelse
                    </td>
                    @if ($canManageTenant)
                    <td class="block sm:table-cell pt-2 sm:px-4 sm:py-2">
                        <span class="sm:hidden block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Kurs freischalten <span class="font-normal">(verbraucht einen eigenen Code)</span></span>
                        {{-- Review: abgelaufene Zugänge (auch gesperrt und inzwischen abgelaufen) lassen sich hier neu freischalten; aktive, widerrufene und gesperrte mit Restlaufzeit haben eigene Aktionen. --}}
                        @php($bookedCourseIds = $membership->user->entitlements->filter(fn ($e) => $e->status === 'revoked' || (in_array($e->status, ['active', 'suspended'], true) && ($e->valid_until === null || $e->valid_until->isFuture())))->pluck('course_id')->all())
                        {{-- Nur Kurse, die die Schule anbietet. --}}
                        @php($unbookedCourses = $courses->whereNotIn('id', $disabledCourseIds)->whereNotIn('id', $bookedCourseIds))
                        @if ($unbookedCourses->isNotEmpty())
                            <form method="POST" action="{{ route('admin.entitlements.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $membership->user_id }}">
                                <select name="course_id" aria-label="Kurs für {{ $membership->user->name }} freischalten" class="min-w-0 flex-1 sm:flex-none rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    @foreach ($unbookedCourses as $course)
                                        @php($freeCount = $freeCourseCouponCounts->get($course->id, 0))
                                        <option value="{{ $course->id }}" @disabled($freeCount < 1) @class(['text-slate-600 dark:text-slate-400' => $freeCount < 1])>
                                            {{ $course->name }} ({{ $freeCount < 1 ? 'keine Codes' : $freeCount.' frei' }})
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs" title="Verbraucht einen eigenen, noch nicht eingelösten Code für diesen Kurs.">Freischalten</button>
                            </form>
                        @else
                            <span class="text-slate-600 dark:text-slate-400 text-xs">alle Kurse gebucht</span>
                        @endif
                    </td>
                    @endif
                    @if ($canManageTenant)
                        <td class="block sm:table-cell pt-2 sm:px-4 sm:py-2">
                            <span class="sm:hidden block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Status</span>
                            @if ($membership->user_id === auth()->id())
                                {{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
                                     Admin-Bereich siezt durchgehend. --}}
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv (Sie)</span>
                            @else
                                <form method="POST" action="{{ route('admin.participants.membership.update', $membership->user_id) }}" class="flex items-center gap-2"
                                      @submit="if ({{ $membership->status === 'active' ? 'true' : 'false' }} && ! confirm('Dieses Konto wirklich sperren?')) $event.preventDefault()">
                                    @csrf
                                    @method('PATCH')
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full {{ $membership->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                        {{ $membership->status === 'active' ? 'aktiv' : 'gesperrt' }}
                                    </span>
                                    <input type="hidden" name="status" value="{{ $membership->status === 'active' ? 'suspended' : 'active' }}">
                                    <button type="submit" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">
                                        {{ $membership->status === 'active' ? 'Sperren' : 'Entsperren' }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div class="p-4">{{ $participants->links() }}</div>
</div>
