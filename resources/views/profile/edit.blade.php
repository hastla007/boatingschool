<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Profile') }}
        </h2>
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
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Meine Produkte</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Über einen Gutschein-Code freigeschaltete Zusatzleistungen.</p>
                    </header>

                    <div class="mt-4 space-y-2">
                        @forelse ($productPurchases as $purchase)
                            <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0">
                                <span class="text-sm text-gray-700 dark:text-gray-200">{{ $purchase->product->name }}</span>
                                <span class="text-xs text-gray-400">{{ $purchase->created_at?->format('d.m.Y') }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Noch keine Produkte gekauft.</p>
                        @endforelse
                    </div>
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
