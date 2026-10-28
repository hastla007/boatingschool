<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Angebote</h2>
    </x-slot>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Deine Angebote von {{ $tenant->name }}</h1>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Alle Upgrades und Kursempfehlungen, die aktuell für dich verfügbar sind &mdash; auch wenn du einen Banner auf einer Kursseite einmal weggeklickt hast, findest du ihn hier wieder.</p>
    </div>

    <div class="space-y-8">
        <div>
            <h2 class="text-lg font-semibold text-slate-800 dark:text-white mb-3">Empfehlungen</h2>

            @if ($addons->isEmpty())
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500 dark:text-slate-400 text-sm">
                    Aktuell sind keine Empfehlungen für dich verfügbar.
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($addons as $addon)
                        @php
                            $addonTemplate = $addon->addonTemplate;
                            $addonBullets = $addon->effectiveBullets();
                            $discountedPrice = $addon->discountedPriceLabel();
                        @endphp
                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border-l-4 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-5"
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
                                        <span class="text-xs text-slate-600 dark:text-slate-400 dark:text-slate-500">{{ $addonTemplate->stat_text }}</span>
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
                                @if ($addon->price_label || $addon->coupon_code)
                                    <div class="text-right text-xs text-slate-500 dark:text-slate-400 leading-snug">
                                        @if ($addon->price_label)
                                            <div class="flex items-center justify-end gap-2 flex-wrap">
                                                @if ($discountedPrice)
                                                    <span class="line-through text-slate-600 dark:text-slate-400 dark:text-slate-500">{{ $addon->price_label }}</span>
                                                    <span class="font-semibold text-base" style="color: var(--brand-primary, #005FD7)">{{ $discountedPrice }}</span>
                                                    <span class="inline-flex items-center text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                        &minus;{{ $addon->discount_percent }}%
                                                    </span>
                                                @else
                                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $addon->price_label }}</span>
                                                @endif
                                            </div>
                                        @endif
                                        @if ($addon->coupon_code)
                                            <div class="mt-0.5">Gutscheincode: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $addon->coupon_code }}</span></div>
                                        @endif
                                    </div>
                                @endif
                                <a href="{{ $addon->offer_url }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition whitespace-nowrap"
                                   style="background-color: var(--brand-primary, #005FD7)">
                                    {{ $addonTemplate->cta_label }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <h2 class="text-lg font-semibold text-slate-800 dark:text-white mb-3">Kursangebote für Dich</h2>

            @if ($promoBanners->isEmpty())
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500 dark:text-slate-400 text-sm">
                    Aktuell sind keine Kursangebote für dich verfügbar.
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($promoBanners as $banner)
                        @php($bannerDiscountedPrice = $banner->discountedPriceLabel())
                        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border-l-4 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-5"
                             style="border-color: var(--brand-secondary, #007A7A)">
                            <div class="flex-1 min-w-0">
                                @if ($banner->headline)
                                    <h3 class="font-semibold text-slate-800 dark:text-white text-sm sm:text-base">{{ $banner->headline }}</h3>
                                @endif
                                @if ($banner->description)
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $banner->description }}</p>
                                @endif
                                @if (! empty($banner->bullets))
                                    <ul class="grid grid-cols-2 gap-x-4 gap-y-2 mt-2">
                                        @foreach ($banner->bullets as $bullet)
                                            <li class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                <x-icon name="check-circle" class="w-3.5 h-3.5 shrink-0" style="color: var(--brand-secondary, #007A7A)" />
                                                {{ $bullet }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0 self-start sm:self-center">
                                @if ($banner->price_label || $banner->coupon_code)
                                    <div class="text-right text-xs text-slate-500 dark:text-slate-400 leading-snug">
                                        @if ($banner->price_label)
                                            <div class="flex items-center justify-end gap-2 flex-wrap">
                                                @if ($bannerDiscountedPrice)
                                                    <span class="line-through text-slate-600 dark:text-slate-400 dark:text-slate-500">{{ $banner->price_label }}</span>
                                                    <span class="font-semibold text-base" style="color: var(--brand-primary, #005FD7)">{{ $bannerDiscountedPrice }}</span>
                                                    <span class="inline-flex items-center text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                        &minus;{{ $banner->discount_percent }}%
                                                    </span>
                                                @else
                                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $banner->price_label }}</span>
                                                @endif
                                            </div>
                                        @endif
                                        @if ($banner->coupon_code)
                                            <div class="mt-0.5">Gutscheincode: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $banner->coupon_code }}</span></div>
                                        @endif
                                    </div>
                                @endif
                                <a href="{{ $banner->offer_url }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition whitespace-nowrap"
                                   style="background-color: var(--brand-primary, #005FD7)">
                                    {{ $banner->cta_label }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
