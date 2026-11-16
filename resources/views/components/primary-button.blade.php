{{-- Wie die übrigen Knöpfe der App in normaler Schreibweise, text-sm (vorher Breeze-Stil: Großbuchstaben, text-xs). --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-semibold text-sm text-white hover:opacity-90 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150']) }}
    style="background-color: var(--brand-primary, #1e293b)">
    {{ $slot }}
</button>
