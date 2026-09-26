@props(['name' => 'circle', 'size' => 18, 'width' => 1.7])

@php
    // A fixed internal map (packages/kit/config/icons.php), never user
    // input, which is what makes the unescaped echo below safe.
    $icons = config('icons', []);
    $path = $icons[$name] ?? ($icons['circle'] ?? '');
@endphp

<svg {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="{{ $width }}" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">{!! $path !!}</svg>
