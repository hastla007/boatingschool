<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Profil
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Deine Buchungen</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Kurse und Zusatzleistungen, die du oder deine Bootsschule bei uns freigeschaltet habt, sowie deine eigenen Bestellungen und Anmeldungen.</p>
                    </header>

                    <div class="mt-4 space-y-2">
                        @forelse ($bookings as $booking)
                            <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0">
                                <div>
                                    <span class="text-sm text-gray-700 dark:text-gray-200">{{ $booking['label'] }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        @if ($booking['source_type'] === 'coupon')
                                            Code {{ $booking['source_reference'] }}
                                        @elseif ($booking['source_type'] === 'self')
                                            Von dir gebucht
                                        @else
                                            Von deiner Bootsschule freigeschaltet
                                        @endif
                                        @isset($booking['status'])
                                            · {{ $booking['status'] }}
                                        @endisset
                                    </span>
                                </div>
                                {{-- Bug-Review (Datum in UTC): ein kurz nach Mitternacht deutscher Zeit freigeschalteter Zugang zeigte sonst noch den Vortag. --}}
                                <span class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ \App\Support\GermanTime::format($booking['date'], 'd.m.Y') }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500 dark:text-slate-400">Noch keine Buchungen.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Deine Daten</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Lade die bei dieser Bootsschule über dich gespeicherten Daten als Datei herunter (Art. 15/20 DSGVO).</p>
                    </header>

                    <a href="{{ route('profile.export') }}"
                       class="mt-4 inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900 dark:focus:ring-gray-300 transition ease-in-out duration-150">
                        Meine Daten exportieren
                    </a>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
