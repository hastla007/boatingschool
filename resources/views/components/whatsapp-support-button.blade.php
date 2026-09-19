@php
    $waLink = (isset($branding) && auth()->check() && ! request()->routeIs('admin.*'))
        ? \App\Support\WhatsAppLink::for(
            $branding,
            auth()->user(),
            request()->route('course') instanceof \App\Models\CourseDefinition ? request()->route('course') : null
        )
        : null;
@endphp
@php
    // Auf der Kursübersicht füllen die Kacheln fast die ganze Seite (nur 20px
    // Abstand zwischen den Reihen) - ein fest positioniertes Widget würde dort
    // immer irgendeine Kachel überlappen. Dort wird es daher im normalen
    // Seitenfluss direkt unter dem Kachelraster platziert statt schwebend.
    $isCoursesIndex = request()->routeIs('courses.index');
@endphp
@if ($waLink)
    @if ($isCoursesIndex)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-3 pb-6 flex justify-end">
            <a href="{{ $waLink }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition max-w-[calc(100vw-2.5rem)]"
               style="background-color: #25D366"
               title="WhatsApp Support - {{ $currentTenant->name }}">
                <x-whatsapp-icon class="w-6 h-6 shrink-0" />
                <span class="hidden sm:inline truncate">{{ $currentTenant->name }}</span>
            </a>
        </div>
    @else
        <a href="{{ $waLink }}" target="_blank" rel="noopener"
           class="fixed bottom-[135px] right-5 z-40 inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition max-w-[calc(100vw-2.5rem)]"
           style="background-color: #25D366"
           title="WhatsApp Support - {{ $currentTenant->name }}">
            <x-whatsapp-icon class="w-6 h-6 shrink-0" />
            <span class="hidden sm:inline truncate">{{ $currentTenant->name }}</span>
        </a>
    @endif
@endif
