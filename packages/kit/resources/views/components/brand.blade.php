@props([
    // Where the mark links to, or null for a plain block (sign-in screens).
    'href' => null,
    // Line under the name; defaults to the product.
    'sub' => null,
])

@php
    $product = Khana\Kit\Ui\Ui::productName();
    $sub ??= $product;
@endphp

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['brand']) }}>
    <span class="brand-mark" aria-hidden="true">{{ strtoupper(substr($product, 0, 3)) }}</span>
    <span class="brand-text">
        <span class="brand-name">Restro One</span>
        @if ($sub)
            <span class="brand-sub">{{ $sub }}</span>
        @endif
    </span>
</{{ $href ? 'a' : 'div' }}>
