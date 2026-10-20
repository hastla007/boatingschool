<p class="text-slate-500 mb-6">Hier sehen Sie die wichtigsten Informationen Ihrer Bootsschule.</p>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
    <x-stat-tile icon="users" :value="$learnerCount" label="Teilnehmer" tone="brand" />
    <x-stat-tile icon="academic-cap" :value="$activeEntitlements" label="Aktive Kurszugänge" />
    <x-stat-tile icon="bolt" :value="$weeklyActivityRate.'%'" label="wöchentlich aktiv" tone="success" />
    <x-stat-tile icon="clipboard-document-check" :value="$recentExamsCount" label="Prüfungen (30 Tage)" tone="warning" />
</div>

<div class="grid grid-cols-2 gap-3 mb-6 max-w-md">
    <x-stat-tile icon="compass" :value="$upcomingAppointmentsNext7Days" label="Praxistermine (7 Tage)" />
    <x-stat-tile icon="compass" :value="$upcomingAppointmentsNext30Days" label="Praxistermine (30 Tage)" />
</div>

<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <button type="button" @click="tab = 'participants'" class="text-left bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0" style="background-color: var(--brand-primary, #005FD7)">
            <x-icon name="users" class="w-5 h-5" />
        </div>
        <div>
            <div class="font-medium text-slate-700 dark:text-slate-200">Teilnehmer verwalten</div>
            <div class="text-xs text-slate-400">Einladen, Kurse freischalten</div>
        </div>
    </button>
    <button type="button" @click="tab = 'branding'" class="text-left bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0" style="background-color: var(--brand-secondary, #00A8A8)">
            <x-icon name="swatch" class="w-5 h-5" />
        </div>
        <div>
            <div class="font-medium text-slate-700 dark:text-slate-200">Branding anpassen</div>
            <div class="text-xs text-slate-400">Logo, Farben, Kontakt</div>
        </div>
    </button>
    <button type="button" @click="tab = 'webshop-links'" class="text-left bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-amber-500">
            <x-icon name="upload" class="w-5 h-5" />
        </div>
        <div>
            <div class="font-medium text-slate-700 dark:text-slate-200">Webshop-Links</div>
            <div class="text-xs text-slate-400">Kauf-Links je Kurs hinterlegen</div>
        </div>
    </button>
    <button type="button" @click="tab = 'courses'" class="text-left bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-indigo-500">
            <x-icon name="academic-cap" class="w-5 h-5" />
        </div>
        <div>
            <div class="font-medium text-slate-700 dark:text-slate-200">Kursauswahl</div>
            <div class="text-xs text-slate-400">Welche Kurse deine Bootsschule anbietet</div>
        </div>
    </button>
    <button type="button" @click="tab = 'coupons'" class="text-left bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-rose-500">
            <x-icon name="bookmark" class="w-5 h-5" />
        </div>
        <div>
            <div class="font-medium text-slate-700 dark:text-slate-200">Coupon-Codes</div>
            <div class="text-xs text-slate-400">Importieren, zuweisen, wer wann eingelöst hat</div>
        </div>
    </button>
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
    <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
        <x-icon name="chart-bar" class="w-4 h-4 text-slate-400" /> Teilnehmer je Phase
    </h3>
    @php
        $phaseColors = [
            'Geprüft' => 'bg-emerald-500',
            'Prüfungstermin fixiert' => 'bg-indigo-500',
            'Praxis angemeldet' => 'bg-amber-500',
            'Lernen' => 'bg-sky-500',
            'Nicht gestartet' => 'bg-slate-300 dark:bg-slate-500',
        ];
    @endphp
    @if ($learnerCount > 0)
        <div class="flex w-full h-3 rounded-full overflow-hidden mb-3">
            @foreach ($learnerPhases as $phase)
                @if ($phase['count'] > 0)
                    <div class="{{ $phaseColors[$phase['label']] }}" style="width: {{ $phase['percent'] }}%" title="{{ $phase['label'] }}: {{ $phase['percent'] }}%"></div>
                @endif
            @endforeach
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
            @foreach ($learnerPhases as $phase)
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $phaseColors[$phase['label']] }}"></span>
                    <div class="min-w-0">
                        <div class="text-slate-700 dark:text-slate-200 font-medium">{{ $phase['percent'] }}%</div>
                        <div class="text-xs text-slate-400 truncate">{{ $phase['label'] }} ({{ $phase['count'] }})</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-slate-500">Noch keine Teilnehmer.</p>
    @endif
</div>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
    <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
        <x-icon name="chart-bar" class="w-4 h-4 text-slate-400" /> Kursfortschritt in der Lernphase
    </h3>
    @if ($learningPhaseLearnerCount > 0)
        @php
            $chartPointCount = $progressDistribution->count();
            $chartPoints = $progressDistribution->values()->map(function ($bucket, $i) use ($chartPointCount) {
                return [
                    'x' => $chartPointCount > 1 ? 5 + ($i / ($chartPointCount - 1)) * 90 : 50,
                    'y' => 95 - ($bucket['percent'] / 100 * 90),
                    'bucket' => $bucket,
                ];
            });
            $polylinePoints = $chartPoints->map(fn ($p) => $p['x'].','.$p['y'])->implode(' ');
        @endphp
        <div class="relative h-32 mb-2">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                <polyline
                    points="{{ $polylinePoints }}"
                    fill="none"
                    stroke="var(--brand-primary, #005FD7)"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
            </svg>
            @foreach ($chartPoints as $point)
                <div
                    class="absolute w-2.5 h-2.5 rounded-full -translate-x-1/2 -translate-y-1/2 ring-2 ring-white dark:ring-slate-800"
                    style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%; background-color: var(--brand-primary, #005FD7)"
                    title="{{ $point['bucket']['label'] }}: {{ $point['bucket']['count'] }} Teilnehmer ({{ $point['bucket']['percent'] }}%)"
                ></div>
            @endforeach
        </div>
        <div class="flex gap-4 text-xs text-slate-400 text-center">
            @foreach ($progressDistribution as $bucket)
                <div class="flex-1">{{ $bucket['label'] }} <span class="text-slate-500">({{ $bucket['count'] }})</span></div>
            @endforeach
        </div>
    @else
        <p class="text-slate-500">Aktuell keine Teilnehmer in der Lernphase.</p>
    @endif
</div>

<div class="grid lg:grid-cols-[2fr_3fr] gap-6">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" /> Kürzlich gebuchte Kurse &amp; Produkte
        </h3>
        <div class="space-y-2 text-sm">
            @forelse ($recentBookings as $booking)
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                    <div>
                        <span class="text-slate-700 dark:text-slate-200 font-medium">{{ $booking['user'] }}</span>
                        <span class="text-slate-400">&ndash; {{ $booking['label'] }}</span>
                        @if ($booking['options']->isNotEmpty())
                            <span class="block text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">+ {{ $booking['options']->implode(', ') }}</span>
                        @endif
                    </div>
                    <span class="text-xs text-slate-400 whitespace-nowrap">{{ $booking['date']->format('d.m.Y') }}</span>
                </div>
            @empty
                <p class="text-slate-500">Keine Buchungen in den letzten 7 Tagen.</p>
            @endforelse
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="clock" class="w-4 h-4 text-slate-400" /> Letzte Aktivitäten
        </h3>
        <div class="space-y-2 text-sm">
            @forelse ($recentActivity as $entry)
                <div class="flex justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                    <span class="text-slate-600 dark:text-slate-300">{{ $entry->description() }}</span>
                    <span class="text-slate-400">{{ $entry->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-slate-500">Noch keine Aktivitäten protokolliert.</p>
            @endforelse
        </div>
    </div>
</div>
