{{--
    The board's headline numbers.

    Its own partial because the poller has to be able to redraw it. The tiles
    sit above the card, outside the fragment the board swaps in, so they are
    sent along with each refresh as an out-of-band template - see the
    data-ajax-oob contract in public/assets/js/ajax-list.js. Before that they
    only ever changed on a full page reload, which on a screen bolted to a
    kitchen wall meant they were wrong for the entire shift.
--}}
@foreach ($flow as $rung)
    @continue ($rung === \App\Models\Order::SERVED)
    <div class="stat">
        <div class="stat-body">
            <div class="stat-label">{{ $labels[$rung] ?? $rung }}</div>
            <div class="stat-value">{{ number_format($counts[$rung] ?? 0) }}</div>
            <span class="text-xs text-muted">
                {{ Str::plural('item', $counts[$rung] ?? 0) }}
            </span>
        </div>
    </div>
@endforeach

{{--
    Only shown when something is actually late. A permanent zero here is a
    tile that teaches a kitchen to stop looking at the row, which is the
    opposite of what it is for.
--}}
@if (($counts['late'] ?? 0) > 0)
    <div class="stat is-late">
        <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
            <x-icon name="clock" :size="21" />
        </div>
        <div class="stat-body">
            <div class="stat-label">Running late</div>
            <div class="stat-value">{{ number_format($counts['late']) }}</div>
            <span class="text-xs text-muted">past the station's time</span>
        </div>
    </div>
@endif
