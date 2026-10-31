@props([
    'user',
    /* Marks the element the header-refresh JS repaints after an upload. */
    'live' => false,
])

@php
    $url = $user->avatarUrl();
@endphp

{{--
    Uploaded picture when there is one, initials otherwise. Both render at
    exactly the same size, so swapping between them never reflows the row.
--}}
<span {{ $attributes->class(['avatar']) }} aria-hidden="true" @if ($live) data-avatar @endif>
    @if ($url)
        <img src="{{ $url }}" alt="">
    @else
        {{ $user->initials() }}
    @endif
</span>
