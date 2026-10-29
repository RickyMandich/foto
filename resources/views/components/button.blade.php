@props([
    'variant' => 'primary', // primary | outline | ghost
    'href' => null,
])

@php
    $baseClasses = 'inline-flex items-center justify-center gap-2 rounded-lg text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-400 min-h-[40px] px-4 py-2 cursor-pointer select-none';
    
    $variants = [
        'primary' => 'bg-sky-500 text-white hover:bg-sky-400 active:bg-sky-600',
        'outline' => 'border border-neutral-600 text-neutral-200 hover:bg-neutral-800 hover:text-white',
        'ghost' => 'text-neutral-300 hover:bg-neutral-800 hover:text-white',
    ];

    $classes = $baseClasses . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif

