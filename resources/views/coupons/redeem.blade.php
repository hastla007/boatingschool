<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Coupon-Code einlösen</h2>
    </x-slot>

    <div class="max-w-md mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <div class="w-12 h-12 rounded-full bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mb-4">
            <x-icon name="bookmark" class="w-6 h-6 text-amber-500" />
        </div>
        <h3 class="font-medium text-slate-800 dark:text-white mb-1">Coupon-Code einlösen</h3>
        <p class="text-sm text-slate-500 mb-4">Hast du einen Code von deiner Bootsschule erhalten? Gib ihn hier ein, um den Kurs oder das Produkt freizuschalten.</p>

        <form method="POST" action="{{ route('coupons.redeem.store') }}" class="space-y-3">
            @csrf
            <div>
                <x-text-input name="code" type="text" class="block w-full uppercase tracking-widest text-center font-mono" placeholder="XXXX-XXXX-XXXX" :value="old('code')" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <button type="submit" class="w-full px-5 py-2.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Code einlösen
            </button>
        </form>
    </div>
</x-app-layout>
