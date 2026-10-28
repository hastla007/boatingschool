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
    // immer irgendeine Kachel überlappen. Dort wird das Widget daher direkt im
    // Kachelraster selbst gerendert (siehe courses/index.blade.php), damit es
    // automatisch in eine freie Zelle statt auf eine Kachel fällt.
    $isCoursesIndex = request()->routeIs('courses.index');

    // Auf den Smart-Learning-Fragenseiten, im Videoplayer und im Video-Quiz
    // überlappt das fest positionierte Widget den "Antworten"-Button bzw.
    // die Video-Steuerleiste. Dort wird es stattdessen rechtsbündig im
    // Footer gerendert (siehe components/site-footer.blade.php).
    $isFooterWhatsappPage = request()->routeIs(
        'learning.show', 'learning.attempts.store', 'video.index', 'video.show',
        'video.quiz.show', 'video.quiz.answer', 'video.quiz.result'
    );

    // exam.intro rendert je nach Kurs entweder die Bogen-Kachelübersicht
    // (exam/papers.blade.php, nur wenn echte Prüfungsbögen vorliegen) oder
    // die schlichte Regelwerk-Seite (exam/intro.blade.php) -- nur bei der
    // Kachelübersicht würde das fest positionierte Widget je nach
    // Scrollposition eine Bogen-Kachel überlappen. Dort wird es daher wie
    // auf der Kursübersicht direkt im letzten Kachelraster gerendert, damit
    // es automatisch in eine freie Zelle statt auf eine Kachel fällt.
    $examCourse = request()->route('course');
    $isExamPapersPage = request()->routeIs('exam.intro')
        && $examCourse instanceof \App\Models\CourseDefinition
        && $examCourse->examPapers()->exists();
@endphp
@if ($waLink && ! $isCoursesIndex && ! $isFooterWhatsappPage && ! $isExamPapersPage)
    <a href="{{ $waLink }}" target="_blank" rel="noopener"
       class="fixed bottom-[135px] right-5 z-40 inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition max-w-[calc(100vw-2.5rem)]"
       {{-- Bug-Review Oberfläche U6: #25D366 (WhatsApp-Logo-Grün) erreicht mit
            weißer Schrift/Icon nur 2,0:1 Kontrast -- #075E54 (WhatsApp-
            eigenes dunkles Markengrün, z. B. aus der App-Kopfzeile) kommt
            auf rechnerisch 7,7:1. --}}
       style="background-color: #075E54"
       title="WhatsApp Support - {{ $currentTenant->name }}">
        <x-whatsapp-icon class="w-6 h-6 shrink-0" />
        <span class="hidden sm:inline truncate">{{ $currentTenant->name }}</span>
    </a>
@endif
