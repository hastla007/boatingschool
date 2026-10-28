@php
    // Siehe components/whatsapp-support-button.blade.php: auf den
    // Smart-Learning-Fragenseiten, im Videoplayer und im Video-Quiz wandert
    // der WhatsApp-Button vom fest positionierten Overlay rechtsbündig in
    // den Footer, damit er nicht mehr den "Antworten"-Button bzw. die
    // Video-Steuerleiste überlappt.
    $showInlineWhatsapp = request()->routeIs(
        'learning.show', 'learning.attempts.store', 'video.index', 'video.show',
        'video.quiz.show', 'video.quiz.answer', 'video.quiz.result'
    );
    $inlineWaLink = ($showInlineWhatsapp && isset($branding) && auth()->check() && ! request()->routeIs('admin.*'))
        ? \App\Support\WhatsAppLink::for(
            $branding,
            auth()->user(),
            request()->route('course') instanceof \App\Models\CourseDefinition ? request()->route('course') : null
        )
        : null;
@endphp
<footer class="py-6 text-xs text-slate-600 dark:text-slate-400 dark:text-slate-500">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 {{ $inlineWaLink ? 'flex flex-col sm:flex-row items-center sm:justify-between gap-3' : 'text-center' }}">
        <div class="text-center {{ $inlineWaLink ? 'sm:text-left' : '' }}">
            <p class="flex items-center justify-center {{ $inlineWaLink ? 'sm:justify-start' : '' }} gap-1">
                Mit <x-icon name="heart" class="w-3.5 h-3.5" style="color: var(--brand-primary, #005FD7)" />
                erstellt von XXX
                @isset($currentTenant)
                    für {{ $currentTenant->name }}
                @endisset
            </p>
            <p>Copyright &copy; {{ now()->year }} XXX, Alle Rechte vorbehalten</p>
        </div>
        @if ($inlineWaLink)
            <a href="{{ $inlineWaLink }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 pl-3 pr-4 py-2 rounded-full text-white text-sm font-medium hover:opacity-90 transition shrink-0"
               {{-- Bug-Review Oberfläche U6: siehe components/whatsapp-support-button.blade.php --}}
               style="background-color: #075E54"
               title="WhatsApp Support - {{ $currentTenant->name }}">
                <x-whatsapp-icon class="w-5 h-5 shrink-0" />
                <span>{{ $currentTenant->name }}</span>
            </a>
        @endif
    </div>
</footer>
