{{-- Store-Knöpfe der Lern-App (MOBILE_API_STORE_URL_*); nur die konfigurierten. --}}
@php($appStoreUrls = ['ios' => config('mobile_api.store_url.ios'), 'android' => config('mobile_api.store_url.android')])
<div class="flex flex-wrap items-center gap-3 shrink-0 {{ $class ?? '' }}">
    @if ($appStoreUrls['ios'])
    <a href="{{ $appStoreUrls['ios'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white hover:opacity-90 whitespace-nowrap">
        <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" class="w-5 h-5 shrink-0" fill="currentColor">
            <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zm3.415-3.132c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.702" />
        </svg>
        <span class="text-sm font-semibold">App Store</span>
    </a>
    @endif
    @if ($appStoreUrls['android'])
    <a href="{{ $appStoreUrls['android'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white hover:opacity-90 whitespace-nowrap">
        <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" class="w-5 h-5 shrink-0" fill="currentColor">
            <path d="M3.609 1.814L13.792 12 3.61 22.186a1.5 1.5 0 01-.79-1.317V3.13a1.5 1.5 0 01.79-1.316zm10.831 10.831l2.86 2.86-11.86 6.803a1.501 1.501 0 01-.451.166zm4.05-4.05l3.144 1.813c.72.417.72 1.46 0 1.876l-3.144 1.813-3.14-3.14zm-14.02-6.782a1.501 1.501 0 01.45.166l11.86 6.802-2.85 2.851z" />
        </svg>
        <span class="text-sm font-semibold">Google Play</span>
    </a>
    @endif
</div>
