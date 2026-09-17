<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Teilnehmer</h2>
    </x-slot>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4 mb-6">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Teilnehmer einladen</h3>
        <form method="POST" action="{{ route('admin.participants.invite') }}" class="flex flex-col sm:flex-row gap-2">
            @csrf
            <input type="text" name="name" placeholder="Name" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <input type="email" name="email" placeholder="E-Mail" required class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Einladen</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">E-Mail</th>
                    <th class="px-4 py-2">Kurse</th>
                    <th class="px-4 py-2">Kurs freischalten</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($participants as $membership)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="px-4 py-2 text-slate-700 dark:text-slate-200">{{ $membership->user->name }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $membership->user->email }}</td>
                        <td class="px-4 py-2 text-slate-500">
                            @forelse ($membership->user->entitlements as $entitlement)
                                <span class="inline-block px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-xs mb-1">
                                    {{ $entitlement->course->name }} ({{ $entitlement->status }})
                                </span>
                            @empty
                                <span class="text-slate-400">keine</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2">
                            <form method="POST" action="{{ route('admin.entitlements.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $membership->user_id }}">
                                <select name="course_id" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs">
                                    @foreach ($courses as $course)
                                        <option value="{{ $course->id }}">{{ $course->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 text-xs">Freischalten</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-app-layout>
