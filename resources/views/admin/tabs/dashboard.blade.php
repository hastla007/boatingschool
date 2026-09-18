<p class="text-slate-500 mb-6">Hier sehen Sie die wichtigsten Informationen Ihrer Bootsschule.</p>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <x-stat-tile icon="users" :value="$learnerCount" label="Teilnehmer" tone="brand" />
    <x-stat-tile icon="academic-cap" :value="$activeEntitlements" label="Aktive Kurszugänge" />
    <x-stat-tile icon="bolt" :value="$weeklyActivityRate.'%'" label="wöchentlich aktiv" tone="success" />
    <x-stat-tile icon="clipboard-document-check" :value="$recentExamsCount" label="Prüfungen (30 Tage)" tone="warning" />
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

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
    <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
        <x-icon name="clock" class="w-4 h-4 text-slate-400" /> Letzte Aktivitäten
    </h3>
    <div class="space-y-2 text-sm">
        @forelse ($recentActivity as $entry)
            <div class="flex justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                <span class="text-slate-600 dark:text-slate-300">{{ $entry->action }}</span>
                <span class="text-slate-400">{{ $entry->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <p class="text-slate-500">Noch keine Aktivitäten protokolliert.</p>
        @endforelse
    </div>
</div>
