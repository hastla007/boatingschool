<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400">
            <a href="{{ route('courses.index') }}" class="hover:underline">Kurse</a>
            <x-icon name="chevron-right" class="w-3.5 h-3.5 text-slate-600 dark:text-slate-400" />
            <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $course->name }}</span>
        </div>
    </x-slot>

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">{{ $course->name }}</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Dein Lernbereich</p>
        </div>
        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400 text-xs text-right">
            <span class="leading-tight">Mehr Wissen.<br>Sicher auf dem Wasser.</span>
            <x-icon name="anchor" class="w-5 h-5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
        </div>
    </div>

    @if ($addonBanner)
        @php
            $addonTemplate = $addonBanner->addonTemplate;
            $addonBullets = $addonBanner->effectiveBullets();
        @endphp
        <div x-data="{ dismissed: false }"
             x-init="dismissed = (() => { try { return localStorage.getItem('addon-banner-dismissed-{{ $addonBanner->addon_template_id }}') === '1' } catch (e) { return false } })()"
             x-show="!dismissed"
             class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border-l-4 p-4 sm:p-5 mb-6 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-5"
             style="border-color: var(--brand-secondary, #007A7A)">
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    @if ($addonTemplate->badge_text)
                        <span class="inline-flex items-center text-[11px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                              style="background-color: color-mix(in srgb, var(--brand-primary, #005FD7) 12%, white); color: var(--brand-primary, #005FD7)">
                            {{ $addonTemplate->badge_text }}
                        </span>
                    @endif
                    @if ($addonTemplate->stat_text)
                        <span class="text-xs text-slate-600 dark:text-slate-400">{{ $addonTemplate->stat_text }}</span>
                    @endif
                </div>
                <h3 class="font-semibold text-slate-800 dark:text-white text-sm sm:text-base">{{ $addonTemplate->headline }}</h3>
                @if (! empty($addonBullets))
                    <ul class="grid grid-cols-2 gap-x-4 gap-y-2 mt-2">
                        @foreach ($addonBullets as $bullet)
                            <li class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                <x-icon name="check-circle" class="w-3.5 h-3.5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
                                {{ $bullet }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="flex flex-col items-end gap-2 shrink-0 self-start sm:self-center">
                <div class="flex items-start gap-2">
                    @if ($addonBanner->price_label || $addonBanner->coupon_code)
                        @php
                            $discountedPrice = $addonBanner->discountedPriceLabel();
                        @endphp
                        <div class="text-right text-xs text-slate-500 dark:text-slate-400 leading-snug">
                            @if ($addonBanner->price_label)
                                <div class="flex items-center justify-end gap-2 flex-wrap">
                                    @if ($discountedPrice)
                                        <span class="line-through text-slate-600 dark:text-slate-400">{{ $addonBanner->price_label }}</span>
                                        <span class="font-semibold text-base" style="color: var(--brand-primary, #005FD7)">{{ $discountedPrice }}</span>
                                        <span class="inline-flex items-center text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            &minus;{{ $addonBanner->discount_percent }}%
                                        </span>
                                    @else
                                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $addonBanner->price_label }}</span>
                                    @endif
                                </div>
                            @endif
                            @if ($addonBanner->coupon_code)
                                <div class="mt-0.5">Gutscheincode: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $addonBanner->coupon_code }}</span></div>
                            @endif
                        </div>
                    @endif
                    <button type="button"
                            @click="dismissed = true; (() => { try { localStorage.setItem('addon-banner-dismissed-{{ $addonBanner->addon_template_id }}', '1') } catch (e) {} })()"
                            class="text-slate-600 dark:text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xl leading-none w-8 h-8 shrink-0 flex items-center justify-center rounded-full hover:bg-slate-100 dark:hover:bg-slate-700 transition"
                            aria-label="Banner schließen">
                        &times;
                    </button>
                </div>
                <a href="{{ $addonBanner->offer_url }}" target="_blank" rel="noopener"
                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition whitespace-nowrap"
                   style="background-color: var(--brand-primary, #005FD7)">
                    {{ $addonTemplate->cta_label }}
                </a>
            </div>
        </div>
    @endif

    @if ($coursePromoBanner)
        <div x-data="{ dismissed: false }"
             x-init="dismissed = (() => { try { return localStorage.getItem('course-promo-banner-dismissed-{{ $coursePromoBanner->id }}-{{ $course->id }}') === '1' } catch (e) { return false } })()"
             x-show="!dismissed"
             class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border-l-4 p-4 sm:p-5 mb-6 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-5"
             style="border-color: var(--brand-secondary, #007A7A)">
            <div class="flex-1 min-w-0">
                @if ($coursePromoBanner->headline)
                    <h3 class="font-semibold text-slate-800 dark:text-white text-sm sm:text-base">{{ $coursePromoBanner->headline }}</h3>
                @endif
                @if ($coursePromoBanner->description)
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $coursePromoBanner->description }}</p>
                @endif
                @if (! empty($coursePromoBanner->bullets))
                    <ul class="grid grid-cols-2 gap-x-4 gap-y-2 mt-2">
                        @foreach ($coursePromoBanner->bullets as $bullet)
                            <li class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                <x-icon name="check-circle" class="w-3.5 h-3.5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
                                {{ $bullet }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="flex flex-col items-end gap-2 shrink-0 self-start sm:self-center">
                <div class="flex items-start gap-2">
                    @if ($coursePromoBanner->price_label || $coursePromoBanner->coupon_code)
                        @php
                            $promoDiscountedPrice = $coursePromoBanner->discountedPriceLabel();
                        @endphp
                        <div class="text-right text-xs text-slate-500 dark:text-slate-400 leading-snug">
                            @if ($coursePromoBanner->price_label)
                                <div class="flex items-center justify-end gap-2 flex-wrap">
                                    @if ($promoDiscountedPrice)
                                        <span class="line-through text-slate-600 dark:text-slate-400">{{ $coursePromoBanner->price_label }}</span>
                                        <span class="font-semibold text-base" style="color: var(--brand-primary, #005FD7)">{{ $promoDiscountedPrice }}</span>
                                        <span class="inline-flex items-center text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            &minus;{{ $coursePromoBanner->discount_percent }}%
                                        </span>
                                    @else
                                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $coursePromoBanner->price_label }}</span>
                                    @endif
                                </div>
                            @endif
                            @if ($coursePromoBanner->coupon_code)
                                <div class="mt-0.5">Gutscheincode: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $coursePromoBanner->coupon_code }}</span></div>
                            @endif
                        </div>
                    @endif
                    <button type="button"
                            @click="dismissed = true; (() => { try { localStorage.setItem('course-promo-banner-dismissed-{{ $coursePromoBanner->id }}-{{ $course->id }}', '1') } catch (e) {} })()"
                            class="text-slate-600 dark:text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xl leading-none w-8 h-8 shrink-0 flex items-center justify-center rounded-full hover:bg-slate-100 dark:hover:bg-slate-700 transition"
                            aria-label="Banner schließen">
                        &times;
                    </button>
                </div>
                <a href="{{ $coursePromoBanner->offer_url }}" target="_blank" rel="noopener"
                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition whitespace-nowrap"
                   style="background-color: var(--brand-primary, #005FD7)">
                    {{ $coursePromoBanner->cta_label }}
                </a>
            </div>
        </div>
    @endif

    <div class="grid lg:grid-cols-[1fr_320px] gap-4 mb-6">
        @if ($comboVideoGroups->isNotEmpty())
            <div class="flex flex-col gap-4">
                @foreach ($comboVideoGroups as $group)
                    <a href="{{ route('video.index', $course) }}?kapitel={{ $group['moduleIds']->implode(',') }}" class="relative rounded-2xl p-5 text-white overflow-hidden hover:opacity-95 transition"
                       style="background: linear-gradient(135deg, color-mix(in srgb, {{ $loop->first ? 'var(--brand-primary, #005FD7)' : 'var(--brand-secondary, #007A7A)' }} 90%, #001233), color-mix(in srgb, {{ $loop->first ? 'var(--brand-primary, #005FD7)' : 'var(--brand-secondary, #007A7A)' }} 45%, #001233));">
                        <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid slice" fill="none">
                            <path d="M-20,38 C130,20 280,55 430,35 S730,18 820,32" stroke="white" stroke-opacity="0.08" stroke-width="1.5" />
                            <path d="M-20,94 C140,110 300,75 460,97 S740,118 820,100" stroke="white" stroke-opacity="0.07" stroke-width="1.5" />
                            <path d="M-20,156 C160,140 320,168 480,150 S760,134 820,153" stroke="white" stroke-opacity="0.06" stroke-width="1.5" />
                            <text x="565" y="30" fill="white" fill-opacity="0.16" font-size="14" font-family="sans-serif">12</text>
                            <text x="565" y="175" fill="white" fill-opacity="0.14" font-size="14" font-family="sans-serif">28</text>
                            @if ($group['motif'] === 'antenna')
                                <g transform="translate(680,100)" stroke="white" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <circle r="58" stroke-opacity="0.10" />
                                    <path d="M0,-30 L-26,52 M0,-30 L26,52 M-16,20 L16,20 M-9,-2 L9,-2 M-21,36 L21,36" stroke-opacity="0.20" stroke-width="2" />
                                    <circle cy="-36" r="6" stroke-opacity="0.22" stroke-width="2" />
                                    <path d="M-20,-52 A26,26 0 0,0 -20,-20 M20,-52 A26,26 0 0,1 20,-20" stroke-opacity="0.18" stroke-width="2" />
                                    <path d="M-34,-64 A44,44 0 0,0 -34,-8 M34,-64 A44,44 0 0,1 34,-8" stroke-opacity="0.12" stroke-width="2" />
                                </g>
                            @elseif ($group['motif'] === 'radio')
                                <g transform="translate(680,100)" stroke="white" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <circle r="58" stroke-opacity="0.10" />
                                    <rect x="-20" y="-30" width="40" height="78" rx="8" stroke-opacity="0.20" stroke-width="2" />
                                    <path d="M10,-30 L10,-62" stroke-opacity="0.20" stroke-width="3" />
                                    <rect x="-12" y="-20" width="24" height="16" rx="2" stroke-opacity="0.18" stroke-width="2" />
                                    <path d="M-10,8 L10,8 M-10,18 L10,18 M-10,28 L10,28 M-10,38 L10,38" stroke-opacity="0.16" stroke-width="2" />
                                </g>
                            @elseif ($group['motif'] === 'compass')
                                <g transform="translate(680,100)" stroke="white">
                                    <circle r="58" stroke-opacity="0.10" fill="none" />
                                    <circle r="44" stroke-opacity="0.13" fill="none" />
                                    <path d="M0,-44 L8,-8 L44,0 L8,8 L0,44 L-8,8 L-44,0 L-8,-8 Z" fill="white" fill-opacity="0.11" stroke="none" />
                                    <text x="0" y="-66" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">N</text>
                                    <text x="0" y="76" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">S</text>
                                    <text x="-69" y="4" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">W</text>
                                    <text x="69" y="4" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">O</text>
                                </g>
                            @else
                                <g transform="translate(680,100)" stroke="white" fill="none" stroke-linecap="round">
                                    <circle r="58" stroke-opacity="0.10" />
                                    <circle cy="-52" r="10" stroke-opacity="0.20" stroke-width="2" />
                                    <path d="M0,-42 L0,38" stroke-opacity="0.20" stroke-width="2" />
                                    <path d="M-22,-24 L22,-24" stroke-opacity="0.20" stroke-width="2" />
                                    <path d="M0,38 C-2,54 -24,58 -36,40" stroke-opacity="0.20" stroke-width="2" />
                                    <path d="M0,38 C2,54 24,58 36,40" stroke-opacity="0.20" stroke-width="2" />
                                </g>
                            @endif
                        </svg>
                        <div class="relative">
                            <div class="text-xs font-semibold tracking-wide uppercase" style="color: var(--brand-secondary, #6EE7E0)">{{ $loop->first ? 'Dein nächster Schritt' : 'Dein Teilkurs' }}</div>
                            <div class="font-bold text-lg mt-1">{{ $group['label'] }}</div>
                            <div class="text-white/70 text-sm">Dein Videokurs für {{ $group['label'] }}</div>
                            <div class="text-white/90 text-sm mt-3">Kursfortschritt {{ $group['percent'] }}&nbsp;%</div>
                            <div class="w-full bg-white/20 rounded-full h-1.5 mt-1 max-w-xs">
                                <div class="h-1.5 rounded-full bg-white" style="width: {{ $group['percent'] }}%"></div>
                            </div>
                            <div class="inline-flex items-center gap-1 mt-3 bg-white rounded-lg px-4 py-2 text-sm font-semibold" style="color: var(--brand-primary, #005FD7)">
                                {{ $group['percent'] >= 100 ? 'Abgeschlossen' : 'Weiterschauen' }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @elseif ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="relative rounded-2xl p-6 text-white overflow-hidden hover:opacity-95 transition"
               style="background: linear-gradient(135deg, color-mix(in srgb, var(--brand-primary, #005FD7) 90%, #001233), color-mix(in srgb, var(--brand-primary, #005FD7) 45%, #001233));">
                <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid slice" fill="none">
                    <path d="M-20,38 C130,20 280,55 430,35 S730,18 820,32" stroke="white" stroke-opacity="0.08" stroke-width="1.5" />
                    <path d="M-20,94 C140,110 300,75 460,97 S740,118 820,100" stroke="white" stroke-opacity="0.07" stroke-width="1.5" />
                    <path d="M-20,156 C160,140 320,168 480,150 S760,134 820,153" stroke="white" stroke-opacity="0.06" stroke-width="1.5" />
                    <text x="565" y="30" fill="white" fill-opacity="0.16" font-size="14" font-family="sans-serif">12</text>
                    <text x="565" y="175" fill="white" fill-opacity="0.14" font-size="14" font-family="sans-serif">28</text>
                    <g transform="translate(680,100)" stroke="white">
                        <circle r="58" stroke-opacity="0.10" fill="none" />
                        <circle r="44" stroke-opacity="0.13" fill="none" />
                        <path d="M0,-44 L8,-8 L44,0 L8,8 L0,44 L-8,8 L-44,0 L-8,-8 Z" fill="white" fill-opacity="0.11" stroke="none" />
                        <text x="0" y="-66" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">N</text>
                        <text x="0" y="76" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">S</text>
                        <text x="-69" y="4" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">W</text>
                        <text x="69" y="4" fill="white" fill-opacity="0.20" font-size="12" font-family="sans-serif" text-anchor="middle">O</text>
                    </g>
                </svg>
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
            <div class="rounded-2xl p-6 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-600 dark:text-slate-400 text-sm">
                Für diesen Kurs ist noch kein Videokurs hinterlegt.
            </div>
        @endif

        <a href="{{ route('progress.show', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 flex flex-col hover:shadow-md transition">
            <div class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Dein Fortschritt</div>
            <div class="flex items-center gap-4">
                <x-progress-ring :percent="$overallPercent" :size="80" :stroke="8" class="shrink-0" />
                <div class="text-sm space-y-1.5 min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full shrink-0" style="background-color: var(--brand-secondary, #007A7A)"></span>
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
            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-400 flex items-center gap-1.5">
                <x-icon name="anchor" class="w-3.5 h-3.5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
                Weiter so! Du bist auf einem guten Weg.
            </div>
        </a>
    </div>

    <div class="flex items-end justify-between mb-3">
        <h3 class="font-semibold text-lg text-slate-800 dark:text-white">Lernbereiche</h3>
        <span class="text-xs text-slate-600 dark:text-slate-400 hidden sm:inline">Wähle einen Bereich und lege los!</span>
    </div>

    @php
        $tileIconBg = 'background-color: color-mix(in srgb, var(--brand-secondary, #007A7A) 15%, white)';
        $tileIconColor = 'color: var(--brand-secondary, #007A7A)';
    @endphp
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <a href="{{ route('learning.overview', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                <x-icon name="bolt" class="w-5 h-5" style="{{ $tileIconColor }}" />
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-800 dark:text-white">Smart-Learning</div>
                <div class="text-xs text-slate-600 dark:text-slate-400">Smarttrainer wählt die nächste Frage für dich</div>
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
                    <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">{{ $knotenPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $knotenPercent }}%; background-color: var(--brand-secondary, #007A7A)"></div>
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
                    <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">{{ $navigationPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $navigationPercent }}%; background-color: var(--brand-secondary, #007A7A)"></div>
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
                <div class="text-xs text-slate-600 dark:text-slate-400">{{ $hasExam ? 'Bereit zum Starten' : 'Noch nicht freigegeben' }}</div>
            </div>
            <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
        </a>

        {{-- Funkscheine: Praxis-Prüfungssimulation und mündliche Prüfung (SRC: Seefunktexte, Vokabeln; UBI: Aufnahme, Abgabe, Buchstabiertafel). --}}
        @if ($funkCatalogs)
            <a href="{{ route('funk.praxis.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="signal" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Praxis-Prüfungssimulation</div>
                    <div class="text-xs text-slate-600 dark:text-slate-400">Notalarm, Meldungen und Funkgerät üben</div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
            @php
                $oralParts = array_filter([
                    in_array('SRC', $funkCatalogs, true) ? 'Seefunktexte und Vokabeln' : null,
                    in_array('UBI', $funkCatalogs, true) ? 'Aufnahme, Abgabe, Buchstabiertafel' : null,
                ]);
            @endphp
            @if ($oralParts)
                <a href="{{ route('funk.oral.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                    <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                        <x-icon name="microphone" class="w-5 h-5" style="{{ $tileIconColor }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-slate-800 dark:text-white">Mündliche Prüfung</div>
                        <div class="text-xs text-slate-600 dark:text-slate-400">{{ implode(' · ', $oralParts) }}</div>
                    </div>
                    <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
                </a>
            @endif
        @endif

        @if ($hasNavigationTasks)
            <a href="{{ route('exam.navigation.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="map-pin" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Navigationsaufgaben</div>
                    <div class="text-xs text-slate-600 dark:text-slate-400">Übung mit Musterlösung</div>
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
                    <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">{{ $praxisPercent }}% angesehen</div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full" style="width: {{ $praxisPercent }}%; background-color: var(--brand-secondary, #007A7A)"></div>
                    </div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @endif

        {{-- Bug-Review T7: verglich bisher den gerundeten $overallPercent --
             49,5% gefestigte Fragen zeigten sich als "50%" und schalteten
             damit fälschlich eine Schwelle von genau 50% frei, obwohl der
             tatsächliche Fortschritt noch knapp darunter lag. Dieselbe
             Lücke wurde für die Addon-/Promo-Banner-Trigger oben im
             Controller (Bug-Review "Gates vergleichen den gerundeten
             Wert") bereits mit $exactPercent geschlossen -- diese Prüfung
             hier blieb dabei übersehen. $overallPercent bleibt für die
             reine Anzeige (Fortschrittsring, "X%/Y%" unten) weiterhin
             gerundet. --}}
        {{-- Prüfungsanmeldung (App\Support\ExamRegistration): meldet sich der Teilnehmer selbst an,
             ersetzt "Prüfung buchen" das Termintool "Praxis & Prüfung" der Bootsschule. --}}
        @if ($selfExamRegistration)
            <a href="{{ route('exam-booking', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="calendar-days" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Prüfung buchen</div>
                    <div class="text-xs text-slate-600 dark:text-slate-400">Anmeldung, Kosten und Ablauf</div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @elseif ($hasPraxisSubmission || $exactPercent >= $examReadinessThreshold)
            <a href="{{ route('praxis-pruefung.index', $course) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" style="{{ $tileIconBg }}">
                    <x-icon name="shield-check" class="w-5 h-5" style="{{ $tileIconColor }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-slate-800 dark:text-white">Praxis &amp; Prüfung</div>
                    <div class="text-xs text-slate-600 dark:text-slate-400">{{ $hasPraxisSubmission ? 'Deine Anmeldung und Termine' : 'Jetzt buchbar' }}</div>
                </div>
                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
            </a>
        @else
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 flex items-center gap-4 text-slate-600 dark:text-slate-400 cursor-not-allowed"
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
        // Bug-Review F9: siehe courses/index.blade.php -- eine noch nicht
        // bestätigte Support-E-Mail wurde hier bisher trotzdem angezeigt.
        $showEmail = $branding && $branding->email_support_enabled && $branding->support_email && $branding->hasVerifiedSupportEmail();
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
                        <p class="w-full text-sm text-slate-500 dark:text-slate-400 text-center">
                            @if ($branding->street) {{ $branding->street }}<br> @endif
                            @if ($branding->postal_code || $branding->city) {{ trim($branding->postal_code.' '.$branding->city) }} @endif
                            @if ($branding->country) &middot; {{ $branding->country }} @endif
                        </p>
                    @endif
                </div>

                <div class="flex-1 text-center sm:self-center">
                    <h3 class="font-semibold text-slate-800 dark:text-white text-lg mb-3">Fragen? Kontaktiere deine Bootsschule!</h3>
                    @if ($showPhone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $branding->phone) }}" class="block text-3xl font-bold whitespace-nowrap hover:opacity-80 transition" style="color: var(--brand-primary, #005FD7)">
                            {{ $branding->phone }}
                        </a>
                    @endif
                    @if ($showPhone && $showEmail)
                        <p class="text-sm text-slate-600 dark:text-slate-400 my-1">oder</p>
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
                            <span class="text-xs text-slate-500 dark:text-slate-400">Wir sind gerne für dich da!</span>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="text-center sm:text-left">
            <h3 class="font-semibold text-slate-800 dark:text-white text-lg">Lerne auch auf deinem Handy!</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Unsere App ist bald verfügbar &mdash; lerne unterwegs, wo und wann du willst.</p>
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
