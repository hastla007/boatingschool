<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Meine Produkte</h1>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Zusatzleistungen wie Fahrstunden oder Praxiseinheiten, die du per Code eingelöst oder von deiner Bootsschule bekommen hast.
        </p>

        @forelse ($items as $item)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 sm:p-5">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center shrink-0 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="check-circle" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h2 class="font-semibold text-slate-800 dark:text-white">{{ $item['name'] }}</h2>
                            {{-- Deutsche Zeit wie im Profil (GermanTime). --}}
                            <span class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ \App\Support\GermanTime::format($item['date'], 'd.m.Y') }}</span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 break-all">{{ $item['source'] }}</p>
                        <p class="text-sm text-slate-700 dark:text-slate-200 mt-2">
                            <span class="font-medium">So geht es weiter:</span> {{ $item['next'] }}
                        </p>
                        @if ($item['link'])
                            <a href="{{ $item['link'] }}" class="inline-flex items-center gap-1 mt-2 text-sm font-medium hover:underline" style="color: var(--brand-primary, #005FD7)">
                                {{ $item['link_label'] }}
                                <x-icon name="chevron-right" class="w-4 h-4" />
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center">
                <p class="text-slate-600 dark:text-slate-300">Du hast noch keine Produkte.</p>
                @if (auth()->user()->roleForTenant($currentTenant ?? null) !== 'instructor')
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Hast du einen Code für eine Fahrstunde oder Praxiseinheit? <a href="{{ route('coupons.redeem') }}" class="underline">Code einlösen</a></p>
                @endif
            </div>
        @endforelse
    </div>
</x-app-layout>
