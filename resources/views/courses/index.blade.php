<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Deine Kurse</h2>
    </x-slot>

    @php
        $gradients = [
            'from-blue-600 to-cyan-500',
            'from-slate-700 to-slate-500',
            'from-emerald-600 to-teal-500',
            'from-indigo-600 to-blue-500',
            'from-amber-600 to-orange-500',
            'from-rose-600 to-pink-500',
        ];
        $courseImages = [
            'SBF-SEE' => 'images/courses/sbf-see.webp',
            'SBF-BIN-MOTOR' => 'images/courses/sbf-binnen.webp',
            'SRC-UBI' => 'images/courses/src-ubi.webp',
            'SRC' => 'images/courses/src.webp',
        ];
    @endphp

    <a href="{{ route('coupons.redeem') }}" class="flex items-center gap-3 rounded-2xl p-4 mb-5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700/60 transition">
        <x-icon name="bookmark" class="w-5 h-5 text-amber-500 shrink-0" />
        <div class="flex-1">
            <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Hast du einen Coupon-Code?</div>
            <div class="text-xs text-slate-400">Code einlösen und Kurs freischalten</div>
        </div>
        <x-icon name="arrow-right" class="w-4 h-4 text-slate-400 shrink-0" />
    </a>

    @if ($courses->isEmpty() && $lockedCourses->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500">
            Noch keine Kurse verfügbar.
        </div>
    @else
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($courses as $entry)
                @php($image = $courseImages[$entry['course']->code] ?? null)
                <a href="{{ route('courses.show', $entry['course']) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden hover:shadow-md transition group">
                    <div class="relative h-32 flex items-center justify-center {{ $image ? 'bg-cover bg-center' : 'bg-gradient-to-br '.$gradients[$loop->index % count($gradients)] }}"
                         @if ($image) style="background-image: url('{{ asset($image) }}')" @endif>
                        @if ($image)
                            <div class="absolute inset-0 bg-black/25"></div>
                        @else
                            <x-icon name="academic-cap" class="w-10 h-10 text-white/30" />
                        @endif
                        <div class="absolute -bottom-6 left-1/2 -translate-x-1/2">
                            <x-progress-ring :percent="$entry['percent']" :size="64" :stroke="6" class="bg-white dark:bg-slate-800 rounded-full shadow" />
                        </div>
                    </div>
                    <div class="pt-9 pb-4 px-4 text-center">
                        <div class="font-semibold text-slate-800 dark:text-white">{{ $entry['course']->name }}</div>
                        <div class="text-xs text-slate-400 mt-1">{{ $entry['course']->modules->pluck('name')->join(' · ') }}</div>
                        <div class="text-xs text-slate-400 mt-2">
                            Freigeschaltet am {{ $entry['entitlement']->valid_from->format('d.m.Y') }}
                        </div>
                    </div>
                </a>
            @endforeach

            @foreach ($lockedCourses as $course)
                @php($webshopUrl = $webshopLinks->get($course->id))
                @php($image = $courseImages[$course->code] ?? null)
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden {{ $webshopUrl ? '' : 'opacity-70' }}">
                    <div class="relative h-32 flex items-center justify-center {{ $image ? 'bg-cover bg-center' : 'bg-gradient-to-br from-slate-300 to-slate-400 dark:from-slate-700 dark:to-slate-600' }}"
                         @if ($image) style="background-image: url('{{ asset($image) }}')" @endif>
                        @if ($image)
                            <div class="absolute inset-0 bg-black/40"></div>
                            <x-icon name="lock-closed" class="relative w-10 h-10 text-white/80" />
                        @else
                            <x-icon name="lock-closed" class="w-10 h-10 text-white/50" />
                        @endif
                    </div>
                    <div class="pt-4 pb-4 px-4 text-center">
                        <div class="font-semibold text-slate-600 dark:text-slate-300">{{ $course->name }}</div>
                        @if ($webshopUrl)
                            <a href="{{ $webshopUrl }}" target="_blank" rel="noopener"
                               class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                                <x-icon name="arrow-right" class="w-3.5 h-3.5" /> Jetzt kaufen
                            </a>
                        @else
                            <div class="text-xs text-slate-400 mt-2 inline-flex items-center gap-1">
                                <x-icon name="lock-closed" class="w-3.5 h-3.5" /> Kein Zugang &mdash; bitte bei deiner Bootsschule anfragen
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
