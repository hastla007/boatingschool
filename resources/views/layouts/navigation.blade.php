@php
    $role = Auth::check() ? Auth::user()->roleForTenant($currentTenant ?? null) : null;
    $isAdmin = in_array($role, ['owner', 'admin', 'instructor'], true);
    $navCourses = Auth::check() && isset($currentTenant)
        ? app(\App\Services\EntitlementService::class)->activeCourses($currentTenant, Auth::user())
        : collect();
@endphp
<nav x-data="{ open: false }" class="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
                    @if (($branding->logo_asset_id ?? null) && $branding->logoAsset)
                        <img src="{{ $branding->logoAsset->storage_path }}" alt="{{ $currentTenant->name }}" class="w-7 h-7 object-contain rounded">
                    @else
                        <x-icon name="anchor" class="w-5 h-5" style="color: var(--brand-primary, #005FD7)" />
                    @endif
                    <span class="font-bold text-slate-800 dark:text-white">{{ $currentTenant->name ?? config('app.name') }}</span>
                </a>

                <div class="hidden sm:flex sm:ms-10 sm:space-x-1">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Lernen</x-nav-link>

                    @if ($navCourses->isNotEmpty())
                        <x-dropdown align="left" width="w-64">
                            <x-slot name="trigger">
                                <button type="button"
                                        class="inline-flex items-center gap-1 px-1 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out {{ request()->routeIs('courses.*') ? 'text-slate-900 dark:text-white' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600' }}"
                                        @style(request()->routeIs('courses.*') ? 'border-color: var(--brand-primary, #005FD7)' : '')>
                                    Kurse
                                    <svg class="fill-current h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                @foreach ($navCourses as $navCourse)
                                    @if (! $loop->first)
                                        <div class="border-t border-slate-100 dark:border-slate-600 my-1"></div>
                                    @endif
                                    @php($subLinkClasses = 'block w-full pl-8 pr-4 py-1.5 text-start text-xs text-slate-500 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-slate-700 dark:hover:text-slate-300 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out')
                                    <x-dropdown-link :href="route('courses.show', $navCourse)" class="font-semibold">{{ $navCourse->name }}</x-dropdown-link>
                                    <a href="{{ route('progress.show', $navCourse) }}" class="{{ $subLinkClasses }}">Fortschritt</a>
                                    <a href="{{ route('favorites.smart-learning', $navCourse) }}" class="{{ $subLinkClasses }}">Favoriten</a>
                                @endforeach
                            </x-slot>
                        </x-dropdown>
                    @else
                        <x-nav-link :href="route('courses.index')" :active="request()->routeIs('courses.*')">Kurse</x-nav-link>
                    @endif

                    <x-nav-link :href="route('coupons.redeem')" :active="request()->routeIs('coupons.redeem')">Coupon-Code einlösen</x-nav-link>
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
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
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                Abmelden
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-slate-400 hover:text-slate-500 hover:bg-slate-100 focus:outline-none">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Lernen</x-responsive-nav-link>
            @foreach ($navCourses as $navCourse)
                <x-responsive-nav-link :href="route('courses.show', $navCourse)" :active="request()->routeIs('courses.show') && ($course ?? null)?->id === $navCourse->id">
                    {{ $navCourse->name }}
                </x-responsive-nav-link>
                <div class="pl-6">
                    <x-responsive-nav-link :href="route('progress.show', $navCourse)" :active="request()->routeIs('progress.show') && ($course ?? null)?->id === $navCourse->id">
                        Fortschritt
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('favorites.smart-learning', $navCourse)" :active="request()->routeIs('favorites.smart-learning', 'favorites.exam') && ($course ?? null)?->id === $navCourse->id">
                        Favoriten
                    </x-responsive-nav-link>
                </div>
            @endforeach
            <x-responsive-nav-link :href="route('coupons.redeem')" :active="request()->routeIs('coupons.redeem')">Coupon-Code einlösen</x-responsive-nav-link>
        </div>
        <div class="pt-4 pb-1 border-t border-slate-200 dark:border-slate-600">
            <div class="px-4">
                <div class="font-medium text-base text-slate-800 dark:text-slate-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-slate-500">{{ Auth::user()->email }}</div>
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
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                        Abmelden
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
