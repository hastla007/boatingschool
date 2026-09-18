<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Kurse</h2>
            <div class="flex gap-2">
                <a href="{{ route('superadmin.modules.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">Module &amp; Fragen</a>
                <a href="{{ route('superadmin.courses.create') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">+ Neuer Kurs</a>
            </div>
        </div>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Typ</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Sitewide</th>
                    <th class="px-4 py-2">Module</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($courses as $course)
                    <tr class="border-t border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 {{ $course->site_enabled ? '' : 'opacity-60' }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('superadmin.courses.show', $course) }}" class="font-medium text-slate-700 dark:text-slate-200 hover:underline">{{ $course->name }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-500 font-mono text-xs">{{ $course->code }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $course->course_type }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs {{ $course->status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $course->status }}</span>
                        </td>
                        <td class="px-4 py-2">
                            @if ($course->site_enabled)
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-rose-50 text-rose-700">deaktiviert</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $course->modules_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-superadmin-layout>
