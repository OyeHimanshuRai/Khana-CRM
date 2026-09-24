{{--
    The day's headline numbers.

    Its own partial so the listing's fragment can carry it: the tiles are
    drawn above the card, outside [data-ajax-list-content], and without this
    they only ever changed on a full page reload. A host who took a booking
    and seated it watched both numbers stay exactly where they were.

    See the data-ajax-oob contract in public/assets/js/ajax-list.js.
--}}
        <div class="stat">
            <div class="stat-icon"><x-icon name="calendar" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Bookings</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
                <span class="text-xs text-muted">{{ number_format($stats['covers']) }} covers expected</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Confirmed</div>
                <div class="stat-value">{{ number_format($stats['confirmed']) }}</div>
                @if ($stats['requested'] > 0)
                    <span class="text-xs text-muted">{{ $stats['requested'] }} still to accept</span>
                @endif
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Seated</div>
                <div class="stat-value">{{ number_format($stats['seated']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Overdue</div>
                <div class="stat-value">{{ number_format($stats['overdue']) }}</div>
                <span class="text-xs text-muted">
                    past their time by {{ $grace }}&nbsp;min — someone has to say what happened
                </span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">No-shows</div>
                <div class="stat-value">{{ number_format($stats['no_show']) }}</div>
            </div>
        </div>
    
