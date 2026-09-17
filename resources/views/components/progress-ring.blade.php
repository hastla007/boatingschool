@props(['percent' => 0, 'size' => 96, 'stroke' => 9, 'label' => null])
@php
    $radius = ($size - $stroke) / 2;
    $circumference = 2 * pi() * $radius;
    $offset = $circumference * (1 - max(0, min(100, $percent)) / 100);
@endphp
<div {{ $attributes->merge(['class' => 'relative inline-flex items-center justify-center']) }} style="width: {{ $size }}px; height: {{ $size }}px;">
    <svg width="{{ $size }}" height="{{ $size }}" class="-rotate-90">
        <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}" stroke-width="{{ $stroke }}" class="stroke-slate-100 dark:stroke-slate-700" fill="none"></circle>
        <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}" stroke-width="{{ $stroke }}"
                stroke="var(--brand-secondary, #00A8A8)" fill="none" stroke-linecap="round"
                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}"
                style="transition: stroke-dashoffset .6s ease"></circle>
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="font-bold text-slate-800 dark:text-white" style="font-size: {{ max(14, $size * 0.22) }}px">{{ (int) round($percent) }}%</span>
        @if ($label)
            <span class="text-[10px] text-slate-400 leading-tight text-center px-1">{{ $label }}</span>
        @endif
    </div>
</div>
