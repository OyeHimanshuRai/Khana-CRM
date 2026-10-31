@props(['label' => 'More information', 'align' => 'start'])

@php
    /*
     | A small "i" that holds the explanation a screen does not need to show all
     | the time.
     |
     | Use it for the sentence that tells you WHY or HOW (what a figure includes,
     | when a setting applies) and keep what is needed to act on the screen -
     | the label, the unit, the value - visible. Opens on hover, keyboard focus
     | or tap; closes on Escape or a tap elsewhere (public/assets/js/info.js).
     |
     | Without the script the popover simply stays hidden, so anything essential
     | must not live only in here.
     */
    $id = 'info-'.\Illuminate\Support\Str::random(8);
@endphp

<span class="info {{ $align === 'end' ? 'is-end' : '' }}" data-info>
    <button type="button" class="info-btn" aria-label="{{ $label }}" aria-expanded="false" aria-controls="{{ $id }}">
        <x-icon name="info" :size="15" />
    </button>
    <span class="info-pop" role="tooltip" id="{{ $id }}">{{ $slot }}</span>
</span>
