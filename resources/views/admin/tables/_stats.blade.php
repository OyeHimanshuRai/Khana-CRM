{{--
    The occupancy row §5 asks the dashboard for, shared by the list and the
    plan so the two can never disagree about how many tables are free.

    Counts cover tables in service only. A table switched off seats nobody,
    and including it would make "available" read higher than the room is.
--}}

<div class="stat-grid">
    <div class="stat">
        <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
        <div class="stat-body">
            <div class="stat-label">Available</div>
            <div class="stat-value">{{ number_format($stats['available']) }}</div>
            <span class="text-xs text-muted">of {{ number_format($stats['total']) }} in service</span>
        </div>
    </div>

    <div class="stat">
        <div class="stat-icon is-warning"><x-icon name="users" :size="21" /></div>
        <div class="stat-body">
            <div class="stat-label">Occupied</div>
            <div class="stat-value">{{ number_format($stats['occupied']) }}</div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-icon is-info"><x-icon name="calendar" :size="21" /></div>
        <div class="stat-body">
            <div class="stat-label">Reserved</div>
            <div class="stat-value">{{ number_format($stats['reserved']) }}</div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
        <div class="stat-body">
            <div class="stat-label">Billing</div>
            <div class="stat-value">{{ number_format($stats['billing']) }}</div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-icon" style="background: var(--panel-alt); color: var(--muted)">
            <x-icon name="clock" :size="21" />
        </div>
        <div class="stat-body">
            <div class="stat-label">Cleaning</div>
            <div class="stat-value">{{ number_format($stats['cleaning']) }}</div>
        </div>
    </div>

    {{--
        Only shown when there is something to act on. A permanent zero is a
        tile that teaches people to stop reading the row.
    --}}
    @if ($stats['unassigned'] > 0)
        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="scan" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">No QR code</div>
                <div class="stat-value">{{ number_format($stats['unassigned']) }}</div>
                @allows('dining.qr.view')
                    <a class="text-xs" href="{{ route('admin.qr.index') }}">Issue them</a>
                @endallows
            </div>
        </div>
    @endif
</div>
