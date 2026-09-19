<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
    </x-slot>

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">{{ $course->name }}</h1>
            <p class="text-sm text-slate-400 mt-0.5">Dein Lernbereich</p>
        </div>
        <div class="flex items-center gap-2 text-slate-400 dark:text-slate-500 text-xs text-right">
            <span class="leading-tight">Mehr Wissen.<br>Sicher auf dem Wasser.</span>
            <x-icon name="anchor" class="w-5 h-5 shrink-0" style="color: var(--brand-secondary, #00A8A8)" />
        </div>
    </div>

    <div class="grid lg:grid-cols-[1fr_320px] gap-4 mb-6">
        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="relative rounded-2xl p-6 text-white overflow-hidden hover:opacity-95 transition"
               style="background: linear-gradient(135deg, color-mix(in srgb, var(--brand-primary, #005FD7) 90%, #001233), color-mix(in srgb, var(--brand-primary, #005FD7) 45%, #001233));">
                <x-icon name="compass" class="absolute -right-6 -bottom-6 w-40 h-40 text-white/10 pointer-events-none" />
                <div class="relative">
                    <div class="text-xs font-semibold tracking-wide uppercase" style="color: var(--brand-secondary, #6EE7E0)">Dein nächster Schritt</div>
                    <div class="font-bold text-xl mt-1">{{ $course->name }}</div>
                    <div class="text-white/70 text-sm">Dein Videokurs für {{ $course->name }}</div>
                    <div class="text-white/90 text-sm mt-3">Kursfortschritt {{ $videoPercent }}&nbsp;%</div>
                    <div class="w-full bg-white/20 rounded-full h-1.5 mt-1 max-w-xs">
                        <div class="h-1.5 rounded-full bg-white" style="width: {{ $videoPercent }}%"></div>
                    </div>
                    <div class="inline-flex items-center gap-1 mt-4 bg-white rounded-lg px-4 py-2 text-sm font-semibold" style="color: var(--brand-primary, #005FD7)">
                        {{ $videoPercent >= 100 ? 'Abgeschlossen' : 'Weiterschauen' }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                    </div>
                </div>
            </a>
        @else
            <div class="rounded-2xl p-6 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 text-sm">
                Für diesen Kurs ist noch kein Videokurs hinterlegt.
            </div>
        @endif

        <a href="{{ route('progress.show', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 flex flex-col hover:shadow-md transition">
            <div class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Dein Fortschritt</div>
            <div class="flex items-center gap-4">
                <x-progress-ring :percent="$overallPercent" :size="80" :stroke="8" class="shrink-0" />
                <div class="text-sm space-y-1.5 min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full shrink-0" style="background-color: var(--brand-secondary, #00A8A8)"></span>
                        <span class="text-slate-500 dark:text-slate-400">Gefestigt</span>
                        <span class="ml-auto font-semibold text-slate-700 dark:text-slate-200">{{ $masteredCount }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-slate-200 dark:bg-slate-600 shrink-0"></span>
                        <span class="text-slate-500 dark:text-slate-400">Offen</span>
                        <span class="ml-auto font-semibold text-slate-700 dark:text-slate-200">{{ $openCount }}</span>
                    </div>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-xs text-slate-400 flex items-center gap-1.5">
                <x-icon name="anchor" class="w-3.5 h-3.5 shrink-0" style="color: var(--brand-secondary, #00A8A8)" />
                Weiter so! Du bist auf einem guten Weg.
            </div>
        </a>
    </div>

    <div class="flex items-end justify-between mb-3">
        <h3 class="font-semibold text-lg text-slate-800 dark:text-white">Lernbereiche</h3>
        <span class="text-xs text-slate-400 hidden sm:inline">Wähle einen Bereich und lege los!</span>
    </div>

    @php
        $tileIconBg = 'background-color: color-mix(in srgb, var(--brand-secondary, #00A8A8) 15%, white)';
        $tileIconColor = 'color: var(--brand-secondary, #00A8A8)';
    @endphp
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <a href="{{ route('learning.overview', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                <x-icon name="bolt" class="w-5 h-5" style="{{ $tileIconColor }}" />
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-800 dark:text-white">Smart-Learning</div>
                <div class="text-xs text-slate-400">Smarttrainer wählt die nächste Frage für dich</div>
            </div>
            <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
        </a>

        @if ($knotenModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $knotenModuleId }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="link" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Knoten</div>
                    <div class="text-xs text-slate-400 mb-1">{{ $knotenPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $knotenPercent }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                    </div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @endif

        @if ($navigationModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $navigationModuleId }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="compass" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Navigation</div>
                    <div class="text-xs text-slate-400 mb-1">{{ $navigationPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $navigationPercent }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                    </div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @endif

        <a href="{{ route('exam.intro', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                <x-icon name="clipboard-document-check" class="w-5 h-5" style="{{ $tileIconColor }}" />
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-800 dark:text-white">Prüfungssimulation</div>
                <div class="text-xs text-slate-400">{{ $hasExam ? 'Bereit zum Starten' : 'Noch nicht freigegeben' }}</div>
            </div>
            <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
        </a>

        @if ($hasNavigationTasks)
            <a href="{{ route('exam.navigation.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="map-pin" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Navigationsaufgaben</div>
                    <div class="text-xs text-slate-400">Übung mit Musterlösung</div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @endif

        @if ($praxisModuleId)
            <a href="{{ route('video.index', $course) }}?kapitel={{ $praxisModuleId }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="play" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Praxisvideos</div>
                    <div class="text-xs text-slate-400 mb-1">{{ $praxisPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $praxisPercent }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                    </div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @endif

        @if ($overallPercent >= $examReadinessThreshold)
            <a href="{{ route('praxis-pruefung.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="shield-check" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Praxis &amp; Prüfung</div>
                    <div class="text-xs text-slate-400">Jetzt buchbar</div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @else
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 flex items-center gap-4 text-slate-400 dark:text-slate-500 cursor-not-allowed"
                 title="Ab {{ $examReadinessThreshold }}% Kursfortschritt buchbar">
                <div class="w-11 h-11 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center shrink-0">
                    <x-icon name="lock-closed" class="w-5 h-5 text-slate-300 dark:text-slate-600" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold">Praxis &amp; Prüfung</div>
                    <div class="text-xs">Ab {{ $examReadinessThreshold }}% Kursfortschritt &middot; {{ $overallPercent }}%/{{ $examReadinessThreshold }}%</div>
                </div>
            </div>
        @endif
    </div>

    @php
        $waLink = ($branding && auth()->check()) ? \App\Support\WhatsAppLink::for($branding, auth()->user(), $course) : null;
        $showPhone = $branding && $branding->phone_support_enabled && $branding->phone;
        $showEmail = $branding && $branding->email_support_enabled && $branding->support_email;
        $hasContactDetails = $branding && ($showPhone || $showEmail || $branding->street || $branding->city || $branding->website);
    @endphp
    @if ($hasContactDetails || $waLink)
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 mb-6 lg:w-[65%]">
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">
                <div class="flex flex-col items-center text-center shrink-0 sm:w-52 sm:self-center">
                    @if ($branding->logo_asset_id && $branding->logoAsset)
                        @php
                            $websiteUrl = $branding->website
                                ? (\Illuminate\Support\Str::startsWith($branding->website, ['http://', 'https://']) ? $branding->website : 'https://'.$branding->website)
                                : null;
                        @endphp
                        @if ($websiteUrl)
                            <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" class="hover:opacity-80 transition">
                                <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name }} Logo" class="max-w-[10.5rem] max-h-[10.5rem] w-auto h-auto object-contain rounded-xl">
                            </a>
                        @else
                            <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name }} Logo" class="max-w-[10.5rem] max-h-[10.5rem] w-auto h-auto object-contain rounded-xl">
                        @endif
                    @endif
                    @if ($branding->street || $branding->city)
                        <p class="w-full text-sm font-semibold text-slate-700 dark:text-slate-200 text-center mt-4">{{ $currentTenant->name }}</p>
                        <p class="w-full text-sm text-slate-500 text-center">
                            @if ($branding->street) {{ $branding->street }}<br> @endif
                            @if ($branding->postal_code || $branding->city) {{ trim($branding->postal_code.' '.$branding->city) }} @endif
                            @if ($branding->country) &middot; {{ $branding->country }} @endif
                        </p>
                    @endif
                </div>

                <div class="flex-1 text-center sm:self-center">
                    <h3 class="font-semibold text-slate-800 dark:text-white text-lg mb-3">Fragen? Kontaktiere Deine Bootsschule!</h3>
                    @if ($showPhone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $branding->phone) }}" class="block text-3xl font-bold whitespace-nowrap hover:opacity-80 transition" style="color: var(--brand-primary, #005FD7)">
                            {{ $branding->phone }}
                        </a>
                    @endif
                    @if ($showPhone && $showEmail)
                        <p class="text-sm text-slate-400 my-1">oder</p>
                    @endif
                    @if ($showEmail)
                        <a href="mailto:{{ $branding->support_email }}" class="block text-lg font-semibold whitespace-nowrap hover:opacity-80 transition" style="color: var(--brand-primary, #005FD7)">
                            {{ $branding->support_email }}
                        </a>
                    @endif
                </div>

                @if ($waLink)
                    <a href="{{ $waLink }}" target="_blank" rel="noopener" class="flex flex-col items-center text-center gap-2 shrink-0 sm:w-56 sm:self-center hover:opacity-80 transition">
                        <img src="{{ asset('images/captain-laptop.webp') }}" alt="Kapitän am Laptop" class="w-[12.5rem] h-[12.5rem] object-contain">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Frag uns auch direkt<br>per WhatsApp!</span>
                    </a>
                @elseif ($hasContactDetails)
                    @if ($showPhone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $branding->phone) }}" class="flex flex-col items-center text-center gap-2 shrink-0 sm:w-56 sm:self-center hover:opacity-80 transition">
                            <img src="{{ asset('images/captain-phone.webp') }}" alt="Kapitän am Telefon" class="w-[12.5rem] h-[12.5rem] object-contain -scale-x-100">
                            <span class="text-xs text-slate-500 dark:text-slate-400">Ruf uns einfach an!</span>
                        </a>
                    @else
                        <div class="flex flex-col items-center text-center gap-2 shrink-0 sm:w-56 sm:self-center">
                            <img src="{{ asset('images/captain-phone.webp') }}" alt="Kapitän am Telefon" class="w-[12.5rem] h-[12.5rem] object-contain -scale-x-100">
                            <span class="text-xs text-slate-500 dark:text-slate-400">Wir sind gerne für Dich da!</span>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="text-center sm:text-left">
            <h3 class="font-semibold text-slate-800 dark:text-white text-lg">Lerne auch auf deinem Handy!</h3>
            <p class="text-sm text-slate-500 mt-1">Unsere App ist bald verfügbar &mdash; lerne unterwegs, wo und wann du willst.</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white opacity-60 cursor-not-allowed whitespace-nowrap" title="Bald verfügbar auf dem App Store">
                <svg viewBox="0 0 24 24" class="w-5 h-5 shrink-0" fill="currentColor">
                    <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zm3.415-3.132c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.702" />
                </svg>
                <span class="text-sm font-semibold">App Store</span>
            </span>
            <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white opacity-60 cursor-not-allowed whitespace-nowrap" title="Bald verfügbar bei Google Play">
                <svg viewBox="0 0 24 24" class="w-5 h-5 shrink-0" fill="currentColor">
                    <path d="M3.609 1.814L13.792 12 3.61 22.186a1.5 1.5 0 01-.79-1.317V3.13a1.5 1.5 0 01.79-1.316zm10.831 10.831l2.86 2.86-11.86 6.803a1.501 1.501 0 01-.451.166zm4.05-4.05l3.144 1.813c.72.417.72 1.46 0 1.876l-3.144 1.813-3.14-3.14zm-14.02-6.782a1.501 1.501 0 01.45.166l11.86 6.802-2.85 2.851z" />
                </svg>
                <span class="text-sm font-semibold">Google Play</span>
            </span>
        </div>
    </div>
</x-app-layout>
