@props(['name' => 'circle', 'size' => 18, 'width' => 1.7])

@php
    /*
     | Inline SVG icon set (stroke style, 24x24 grid).
     |
     | The map lives in config/icons.php so screens that let an operator pick
     | an icon can enumerate and validate the same list. It is a fixed
     | internal map, never user input, which is what makes the unescaped
     | echo below safe.
     */
    $icons = config('icons', []);
    $path = $icons[$name] ?? ($icons['circle'] ?? '');
@endphp

<svg
    {{ $attributes }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="{{ $width }}"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
>{!! $path !!}</svg>
