@props(['name'])
<svg {{ $attributes->merge(['class' => 'size-5', 'aria-hidden' => 'true', 'fill' => 'none', 'viewBox' => '0 0 24 24', 'stroke' => 'currentColor', 'stroke-width' => '1.5']) }}>
    @include('icons.'.$name)
</svg>

