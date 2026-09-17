@props(['icon' => 'chart-bar', 'value', 'label', 'tone' => 'neutral'])
@php
    $tones = [
        'neutral' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
        'brand' => 'text-white',
        'success' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300',
        'danger' => 'bg-rose-50 text-rose-500 dark:bg-rose-900/30 dark:text-rose-300',
        'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300',
    ];
    $iconClasses = $tones[$tone] ?? $tones['neutral'];
    $valueClasses = match ($tone) {
        'success' => 'text-emerald-600',
        'danger' => 'text-rose-500',
        'warning' => 'text-amber-500',
        default => 'text-slate-800 dark:text-white',
    };
@endphp
<div {{ $attributes->merge(['class' => 'bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3']) }}>
    <div @class(["w-10 h-10 rounded-xl flex items-center justify-center shrink-0", $iconClasses])
         @style($tone === 'brand' ? 'background-color: var(--brand-primary, #005FD7)' : '')>
        <x-icon :name="$icon" class="w-5 h-5" />
    </div>
    <div class="min-w-0">
        <div @class(["text-xl font-bold leading-tight", $valueClasses])>{{ $value }}</div>
        <div class="text-xs text-slate-400 truncate">{{ $label }}</div>
    </div>
</div>
