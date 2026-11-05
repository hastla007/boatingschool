<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Systemstatus</h1>
    </x-slot>

    @php($badge = [
        'ok' => ['OK', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
        'warn' => ['Warnung', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
        'fail' => ['Fehler', 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'],
    ])
    <div class="max-w-4xl space-y-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 flex flex-wrap items-center gap-3 text-sm">
            <span class="font-medium text-slate-700 dark:text-slate-200">Go-live-Check:</span>
            @foreach (['fail', 'warn', 'ok'] as $status)
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge[$status][1] }}">{{ $counts[$status] ?? 0 }} {{ $badge[$status][0] }}</span>
            @endforeach
            <span class="text-xs text-slate-600 dark:text-slate-400 w-full sm:w-auto sm:ml-auto">Auf dem Server auch per <code>php artisan app:check-production</code>.</span>
        </div>

        @foreach ($groups as $group => $checks)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">{{ $group }}</h3>
                <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($checks as $check)
                        <li class="py-2 flex items-start gap-3 text-sm">
                            <span class="shrink-0 w-20 text-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$check['status']][1] }}">{{ $badge[$check['status']][0] }}</span>
                            <span class="min-w-0">
                                <span class="block text-slate-700 dark:text-slate-200">{{ $check['label'] }}</span>
                                @if ($check['detail'] !== '')
                                    <span class="block text-xs text-slate-600 dark:text-slate-400 break-words">{{ $check['detail'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</x-superadmin-layout>
