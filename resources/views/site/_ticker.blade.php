{{--
    The scrolling strip across the top of the home page: a row of short messages that moves
    sideways, separated by a diamond, and starts again without a gap.

    The list is written twice so the loop is seamless (the second copy is hidden from screen
    readers); it stops under a pointer and for anybody who asked their device for less motion.
    Messages: Settings > Landing page > Home Page Scrolling Strip, one per line.
--}}
@php
    /** @var array<int, array{text: string, url: ?string}> $messages */
@endphp
@if (! empty($messages))
    <div class="lp-ticker" role="region" aria-label="Announcements">
        <div class="lp-ticker-track">
            @foreach ([false, true] as $copy)
                <ul class="lp-ticker-list" @if ($copy) aria-hidden="true" @endif>
                    @foreach ($messages as $message)
                        <li>
                            @if (! empty($message['url']))
                                <a href="{{ $message['url'] }}" @if ($copy) tabindex="-1" @endif>{{ $message['text'] }}</a>
                            @else
                                <span>{{ $message['text'] }}</span>
                            @endif
                        </li>
                        <li class="lp-ticker-sep" aria-hidden="true">&#10022;</li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </div>
@endif
