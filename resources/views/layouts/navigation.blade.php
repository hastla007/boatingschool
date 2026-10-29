@php
    $role = Auth::check() ? Auth::user()->roleForTenant($currentTenant ?? null) : null;
    $isAdmin = in_array($role, ['owner', 'admin', 'instructor'], true);
    $navCourses = Auth::check() && isset($currentTenant)
        ? app(\App\Services\EntitlementService::class)->activeCourses($currentTenant, Auth::user())
        : collect();
    $hasWillkommensbox = Auth::check() && isset($currentTenant) && Auth::user()->hasWillkommensboxAccess($currentTenant);
@endphp
<nav x-data="{ open: false }" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
                    {{-- Bug-Review Oberfläche U4: das Logo erschien bisher
                         nur auf der Kursseite, nicht in der Navigation. --}}
                    @if (isset($branding) && $branding->logo_asset_id && $branding->logoAsset)
                        <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name ?? config('app.name') }} Logo" class="w-7 h-7 object-contain rounded shrink-0">
                    @else
                        <x-icon name="anchor" class="w-5 h-5" style="color: var(--brand-primary, #005FD7)" />
                    @endif
                    <span class="font-bold text-slate-800 dark:text-white">{{ $currentTenant->name ?? config('app.name') }}</span>
                </a>

                <div class="hidden lg:flex lg:ms-16 lg:space-x-1">
                    @php($coursesMenuActive = request()->routeIs('dashboard') || request()->routeIs('courses.*'))
                    @php($chevron = '<svg class="fill-current h-3 w-3 shrink-0 transition-transform" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>')
                    <x-dropdown align="left" width="w-64">
                        <x-slot name="trigger">
                            <button type="button"
                                    :aria-expanded="open" aria-haspopup="true"
                                    class="inline-flex items-center gap-1 whitespace-nowrap px-1 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 {{ $coursesMenuActive ? 'text-slate-900 dark:text-white' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600' }}"
                                    @style($coursesMenuActive ? 'border-color: var(--brand-primary, #005FD7)' : '')>
                                Kurse
                                <svg class="fill-current h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            {{-- "Lernen" ist der einzige Top-Level-Eintrag -- die gebuchten Kurse (inkl. ihrer Fortschritt-/Favoriten-Untermenüs) sind wiederum Untermenüs von "Lernen". --}}
                            <div x-data="{ lernenOpen: true }">
                                <div class="flex items-center">
                                    <a href="{{ route('dashboard') }}" @class(['flex-1 block px-4 py-2 text-start text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out', 'font-semibold' => request()->routeIs('dashboard')])>Lernen</a>
                                    <button type="button" @click.stop="lernenOpen = ! lernenOpen" :aria-expanded="lernenOpen" aria-label="Untermenü ein-/ausblenden" class="pe-3 text-slate-600 dark:text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded">
                                        <span :class="lernenOpen ? 'rotate-90' : ''" class="inline-block transition-transform">{!! $chevron !!}</span>
                                    </button>
                                </div>
                                <div x-show="lernenOpen" class="border-t border-slate-100 dark:border-slate-600 pt-1">
                                    @if ($navCourses->isNotEmpty())
                                        @foreach ($navCourses as $navCourse)
                                            <div x-data="{ open: false }">
                                                <div class="flex items-center">
                                                    <a href="{{ route('courses.show', $navCourse) }}" class="flex-1 block pl-8 pr-2 py-1.5 text-start text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">{{ $navCourse->name }}</a>
                                                    <button type="button" @click.stop="open = ! open" :aria-expanded="open" aria-label="Untermenü ein-/ausblenden" class="pe-3 text-slate-600 dark:text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded">
                                                        <span :class="open ? 'rotate-90' : ''" class="inline-block transition-transform">{!! $chevron !!}</span>
                                                    </button>
                                                </div>
                                                <div x-show="open" style="display: none">
                                                    <a href="{{ route('progress.show', $navCourse) }}" class="block w-full pl-16 pr-4 py-1.5 text-start text-xs text-slate-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-slate-700 dark:hover:text-slate-300 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">Fortschritt</a>
                                                    <a href="{{ route('favorites.smart-learning', $navCourse) }}" class="block w-full pl-16 pr-4 py-1.5 text-start text-xs text-slate-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-slate-700 dark:hover:text-slate-300 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">Favoriten</a>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <x-dropdown-link :href="route('courses.index')" class="pl-8">Deine Kurse</x-dropdown-link>
                                    @endif
                                </div>
                            </div>
                        </x-slot>
                    </x-dropdown>

                    <x-nav-link :href="route('tips.index')" :active="request()->routeIs('tips.index')">Tipps & Tricks</x-nav-link>

                    <x-nav-link :href="route('offers.index')" :active="request()->routeIs('offers.index')">Angebote</x-nav-link>
                </div>
            </div>

            <div class="hidden lg:flex lg:items-center lg:ms-6 gap-4">
                @if ($hasWillkommensbox)
                    <x-nav-link :href="route('willkommensbox.index')" :active="request()->routeIs('willkommensbox.index')">Willkommensbox</x-nav-link>
                @endif
                {{-- Sicherheitsreview H3: ein Instructor ist Lehrkraft, kein
                     Kursteilnehmer -- für ihn ist die Route serverseitig
                     gesperrt (tenant.role:owner,admin,learner), das hier
                     vermeidet nur einen Link ins Leere. --}}
                @if ($role !== 'instructor')
                    <x-nav-link :href="route('coupons.redeem')" :active="request()->routeIs('coupons.redeem')">Coupon-Code einlösen</x-nav-link>
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button :aria-expanded="open" aria-haspopup="true" class="inline-flex items-center whitespace-nowrap px-1 pt-1 border-b-2 border-transparent text-sm leading-5 font-medium text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition ease-in-out duration-150">
                            <div class="max-w-[10rem] truncate">{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Profil</x-dropdown-link>
                        @if ($isAdmin)
                            <x-dropdown-link :href="route('admin.dashboard')">Bootsschul-Admin</x-dropdown-link>
                        @endif
                        @if (Auth::user()->is_superadmin)
                            <x-dropdown-link :href="route('superadmin.dashboard')">Login auf Plattform</x-dropdown-link>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" @click="$event.preventDefault(); $el.closest('form').submit()">
                                Abmelden
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center lg:hidden">
                {{-- Bug-Review Oberfläche U7: Hamburger-Button ohne
                     erkennbaren Namen (nur ein SVG-Icon) und ohne
                     aria-expanded/aria-controls, dazu focus:outline-none
                     ohne sichtbaren Ersatz-Fokusring. --}}
                <button @click="open = ! open" :aria-expanded="open" aria-controls="mobile-menu" aria-label="Menü öffnen/schließen" class="inline-flex items-center justify-center p-2 rounded-md text-slate-600 dark:text-slate-400 hover:text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div id="mobile-menu" :class="{'block': open, 'hidden': ! open}" class="hidden lg:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <div class="px-4 pt-1 pb-0.5 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-400">Kurse</div>
            <div class="pl-6">
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Lernen</x-responsive-nav-link>
            </div>
            @forelse ($navCourses as $navCourse)
                <div class="pl-12">
                    <x-responsive-nav-link :href="route('courses.show', $navCourse)" :active="request()->routeIs('courses.show') && ($course ?? null)?->id === $navCourse->id">
                        {{ $navCourse->name }}
                    </x-responsive-nav-link>
                </div>
                <div class="pl-20">
                    <x-responsive-nav-link :href="route('progress.show', $navCourse)" :active="request()->routeIs('progress.show') && ($course ?? null)?->id === $navCourse->id">
                        Fortschritt
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('favorites.smart-learning', $navCourse)" :active="request()->routeIs('favorites.smart-learning', 'favorites.exam') && ($course ?? null)?->id === $navCourse->id">
                        Favoriten
                    </x-responsive-nav-link>
                </div>
            @empty
                <div class="pl-12">
                    <x-responsive-nav-link :href="route('courses.index')" :active="request()->routeIs('courses.*')">Deine Kurse</x-responsive-nav-link>
                </div>
            @endforelse
            <x-responsive-nav-link :href="route('tips.index')" :active="request()->routeIs('tips.index')">Tipps & Tricks</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('offers.index')" :active="request()->routeIs('offers.index')">Angebote</x-responsive-nav-link>
            @if ($hasWillkommensbox)
                <x-responsive-nav-link :href="route('willkommensbox.index')" :active="request()->routeIs('willkommensbox.index')">Willkommensbox</x-responsive-nav-link>
            @endif
            @if ($role !== 'instructor')
                <x-responsive-nav-link :href="route('coupons.redeem')" :active="request()->routeIs('coupons.redeem')">Coupon-Code einlösen</x-responsive-nav-link>
            @endif
        </div>
        <div class="pt-4 pb-1 border-t border-slate-200 dark:border-slate-600">
            <div class="px-4">
                <div class="font-medium text-base text-slate-800 dark:text-slate-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-slate-500 dark:text-slate-400">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">Profil</x-responsive-nav-link>
                @if ($isAdmin)
                    <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">Bootsschul-Admin</x-responsive-nav-link>
                @endif
                @if (Auth::user()->is_superadmin)
                    <x-responsive-nav-link :href="route('superadmin.dashboard')">Login auf Plattform</x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" @click="$event.preventDefault(); $el.closest('form').submit()">
                        Abmelden
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
