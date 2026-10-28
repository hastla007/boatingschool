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
            <button type="button" @click="showAddress = ! showAddress" class="text-xs text-slate-500 hover:underline">
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
        <div x-data="{ showCode: {{ $errors->has('code') ? 'true' : 'false' }} }">
            <button type="button" @click="showCode = ! showCode" class="text-xs text-slate-500 hover:underline">
                <span x-show="! showCode" @if ($errors->has('code')) style="display: none" @endif>+ Code zuweisen (optional)</span>
                <span x-show="showCode" @unless ($errors->has('code')) style="display: none" @endunless>&minus; Code ausblenden</span>
            </button>
            <div x-show="showCode" @unless ($errors->has('code')) style="display: none" @endunless class="mt-2">
                <label for="invite_code" class="sr-only">Coupon-Code</label>
                <input type="text" id="invite_code" name="code" value="{{ old('code') }}" placeholder="XXXX-XXXX-XXXX" class="w-full sm:w-64 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">
                <x-input-error :messages="$errors->get('code')" class="mt-1" />
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                    Ein eigener Code (Kurs oder Produkt) wird dem Teilnehmer direkt bei der Einladung zugewiesen und eingelöst.
                </p>
            </div>
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Einladen</button>
    </form>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">E-Mail</th>
                <th class="px-4 py-2">Kurse</th>
                <th class="px-4 py-2">Produkte</th>
                <th class="px-4 py-2">
                    Kurs freischalten
                    <span class="block text-[11px] font-normal text-slate-500 dark:text-slate-400">verbraucht einen eigenen Code</span>
                </th>
                @if ($canManageTenant)
                    <th class="px-4 py-2">Status</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($participants as $membership)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2">
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
                    </td>
                    <td class="px-4 py-2 text-slate-500">{{ $membership->user->email }}</td>
                    <td class="px-4 py-2 text-slate-500">
                        @forelse ($membership->user->entitlements as $entitlement)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs mb-1">
                                {{ $entitlement->course->name }} ({{ $entitlement->status }})
                            </span>
                        @empty
                            <span class="text-slate-600 dark:text-slate-400">keine</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-2 text-slate-500">
                        @forelse ($productPurchases->get($membership->user_id, collect()) as $purchase)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-xs mb-1">
                                {{ $purchase->product->name }}
                            </span>
                        @empty
                            <span class="text-slate-600 dark:text-slate-400">keine</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-2">
                        @php($bookedCourseIds = $membership->user->entitlements->pluck('course_id')->all())
                        @php($unbookedCourses = $courses->whereNotIn('id', $bookedCourseIds))
                        @if (! $canManageTenant)
                            <span class="text-slate-300 dark:text-slate-600 text-xs">—</span>
                        @elseif ($unbookedCourses->isNotEmpty())
                            <form method="POST" action="{{ route('admin.entitlements.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $membership->user_id }}">
                                <select name="course_id" aria-label="Kurs für {{ $membership->user->name }} freischalten" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
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
                    @if ($canManageTenant)
                        <td class="px-4 py-2">
                            @if ($membership->user_id === auth()->id())
                                {{-- Bug-Review (Du/Sie im Admin-Bereich gemischt): der restliche
                                     Admin-Bereich siezt durchgehend. --}}
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv (Sie)</span>
                            @else
                                <form method="POST" action="{{ route('admin.participants.membership.update', $membership->user_id) }}" class="flex items-center gap-2">
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
