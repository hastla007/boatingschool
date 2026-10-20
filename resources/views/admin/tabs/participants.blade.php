<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
    <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
        <x-icon name="envelope" class="w-4 h-4 text-slate-400" /> Teilnehmer einladen
    </h3>
    <form method="POST" action="{{ route('admin.participants.invite') }}" class="space-y-2">
        @csrf
        <div class="flex flex-col sm:flex-row gap-2">
            <input type="text" name="name" placeholder="Name" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <input type="email" name="email" placeholder="E-Mail" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div x-data="{ showAddress: false }">
            <button type="button" @click="showAddress = ! showAddress" class="text-xs text-slate-500 hover:underline">
                <span x-show="! showAddress">+ Adresse hinterlegen (optional)</span>
                <span x-show="showAddress" style="display: none">&minus; Adresse ausblenden</span>
            </button>
            <div x-show="showAddress" style="display: none" class="mt-2 grid sm:grid-cols-[2fr_1fr] gap-2">
                <input type="text" name="street" placeholder="Straße & Hausnummer" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <input type="text" name="postal_code" placeholder="PLZ" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <input type="text" name="city" placeholder="Wohnort" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <select name="country" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="">Land wählen&hellip;</option>
                    @foreach (\App\Support\Countries::OPTIONS as $countryOption)
                        <option value="{{ $countryOption }}">{{ $countryOption }}</option>
                    @endforeach
                </select>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Das Geburtsdatum muss der Teilnehmer für die Anmeldung zu Praxis &amp; Prüfung selbst im eigenen Profil angeben.
            </p>
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Einladen</button>
    </form>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">E-Mail</th>
                <th class="px-4 py-2">Kurse</th>
                <th class="px-4 py-2">Produkte</th>
                <th class="px-4 py-2">Kurs freischalten</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($participants as $membership)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
                                {{ strtoupper(substr($membership->user->name, 0, 1)) }}
                            </div>
                            <span class="text-slate-700 dark:text-slate-200">{{ $membership->user->name }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-2 text-slate-500">{{ $membership->user->email }}</td>
                    <td class="px-4 py-2 text-slate-500">
                        @forelse ($membership->user->entitlements as $entitlement)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-xs mb-1">
                                {{ $entitlement->course->name }} ({{ $entitlement->status }})
                            </span>
                        @empty
                            <span class="text-slate-400">keine</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-2 text-slate-500">
                        @forelse ($productPurchases->get($membership->user_id, collect()) as $purchase)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-xs mb-1">
                                {{ $purchase->product->name }}
                            </span>
                        @empty
                            <span class="text-slate-400">keine</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-2">
                        @php($bookedCourseIds = $membership->user->entitlements->pluck('course_id')->all())
                        @php($unbookedCourses = $courses->whereNotIn('id', $bookedCourseIds))
                        @if ($unbookedCourses->isNotEmpty())
                            <form method="POST" action="{{ route('admin.entitlements.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $membership->user_id }}">
                                <select name="course_id" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    @foreach ($unbookedCourses as $course)
                                        @php($freeCount = $freeCourseCouponCounts->get($course->id, 0))
                                        <option value="{{ $course->id }}" @disabled($freeCount < 1) @class(['text-slate-400' => $freeCount < 1])>
                                            {{ $course->name }} ({{ $freeCount < 1 ? 'keine Codes' : $freeCount.' frei' }})
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs">Freischalten</button>
                            </form>
                        @else
                            <span class="text-slate-400 text-xs">alle Kurse gebucht</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
