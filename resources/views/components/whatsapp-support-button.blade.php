@php
    $waLink = (isset($branding) && auth()->check() && ! request()->routeIs('admin.*'))
        ? \App\Support\WhatsAppLink::for(
            $branding,
            auth()->user(),
            request()->route('course') instanceof \App\Models\CourseDefinition ? request()->route('course') : null
        )
        : null;
@endphp
@if ($waLink)
    <a href="{{ $waLink }}" target="_blank" rel="noopener"
       class="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition max-w-[calc(100vw-2.5rem)]"
       style="background-color: #25D366"
       title="WhatsApp Support - {{ $currentTenant->name }}">
        <x-whatsapp-icon class="w-6 h-6 shrink-0" />
        <span class="hidden sm:inline truncate">{{ $currentTenant->name }}</span>
    </a>
@endif
