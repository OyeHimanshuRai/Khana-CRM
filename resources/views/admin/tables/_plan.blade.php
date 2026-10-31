{{--
    The canvas itself: one absolutely-positioned tile per table, placed by the
    percentage pair on the row.

    Percentages rather than pixels, so the same plan reads correctly on a
    counter monitor and on the tablet a captain carries. See the migration.

    Drag-to-arrange is wired in js/floor-plan.js, which only attaches when the
    canvas says the reader may rearrange it - without the right, the tiles are
    still clickable but do not move.
--}}

@if (! $floor)
    <div class="empty">
        <x-icon name="building" :size="28" />
        <h3>No dining areas yet</h3>
        <p class="text-sm">Create an area, then place its tables on the plan.</p>
        @allows('dining.floors.create')
            <a class="btn btn-primary btn-sm" href="{{ route('admin.floors.index') }}">Go to Dining Areas</a>
        @endallows
    </div>
@elseif ($tables->isEmpty())
    <div class="empty">
        <x-icon name="grid" :size="28" />
        <h3>{{ $floor->name }} has no tables</h3>
        <p class="text-sm">Add one and it appears here, ready to be dragged into place.</p>
        @allows('dining.tables.create')
            <a class="btn btn-primary btn-sm"
               href="{{ route('admin.tables.create', ['floor_id' => $floor->id]) }}"
               data-modal="{{ route('admin.tables.create', ['floor_id' => $floor->id]) }}"
               data-modal-title="Add Table"
               data-modal-sub="{{ $floor->name }}">Add a table</a>
        @endallows
    </div>
@else
    {{-- Always present, so a refresh can swap it without leaving a stale count. --}}
    <p class="plan-attention {{ $attention ? 'is-active' : '' }}" role="status" data-plan-attention="{{ $attention }}">
        @if ($attention)
            <x-icon name="bell" :size="15" />
            {{ $attention }} {{ \Illuminate\Support\Str::plural('table', $attention) }} {{ $attention === 1 ? 'needs' : 'need' }} attention
        @else
            Nothing needs attention right now.
        @endif
    </p>

    <div class="floor-plan"
         data-floor-plan
         @canany(['dining.tables.adjust']) data-plan-editable="1" @endcanany
         data-position-url="{{ route('admin.tables.position', ['table' => '__ID__']) }}"
         data-csrf="{{ csrf_token() }}"
         aria-label="Floor plan for {{ $floor->name }}">

        @foreach ($tables as $table)
            @php($ins = $insights[$table->id] ?? ['reservation' => null, 'alerts' => []])
            <div class="plan-table {{ $table->statusTone() ? 'is-'.$table->statusTone() : '' }} {{ $ins['alerts'] ? 'has-alert' : '' }} {{ ($positions[$table->id]['auto'] ?? false) ? 'is-auto' : '' }}"
                 data-plan-tile
                 data-table-id="{{ $table->id }}"
                 style="--x: {{ $positions[$table->id]['x'] ?? 0 }}; --y: {{ $positions[$table->id]['y'] ?? 0 }}">

                <button type="button" class="plan-table-face"
                        data-modal="{{ route('admin.tables.show', $table) }}"
                        data-modal-title="Table {{ $table->name }}"
                        data-modal-sub="{{ $table->fullName() }}"
                        aria-label="Table {{ $table->name }}, seats {{ $table->capacity }}, {{ $table->statusLabel() }}">
                    <span class="plan-table-name">{{ $table->name }}</span>
                    <span class="plan-table-seats">{{ $table->capacity }} seats</span>
                    <span class="plan-table-status">{{ $table->statusLabel() }}</span>

                    {{--
                        How long the party has been sitting. Only when there
                        is one: a duration on an empty table would be noise,
                        and this is the number a floor manager scans for.
                    --}}
                    @if ($table->currentSession)
                        <span class="plan-table-since">
                            {{ $table->currentSession->seatedMinutes() }} min
                        </span>
                    @endif

                    {{-- The next booking for this table, from an hour before its time. --}}
                    @if ($ins['reservation'])
                        <span class="plan-table-booking {{ in_array(\App\Services\FloorPlanInsights::LATE_BOOKING, $ins['alerts'], true) ? 'is-late' : '' }}">
                            {{ $ins['reservation']->reserved_for->format('H:i') }}
                            · {{ \Illuminate\Support\Str::limit($ins['reservation']->guest_name, 14) }}
                            · {{ $ins['reservation']->party_size }}
                        </span>
                    @endif

                    @foreach ($ins['alerts'] as $alert)
                        <span class="plan-table-alert">{{ \App\Services\FloorPlanInsights::LABELS[$alert] }}</span>
                    @endforeach
                </button>

                {{--
                    Straight to the bill from the tile.

                    A guest asking for the bill is the most common thing that
                    happens on this screen after seating one, and routing it
                    through the table's modal would be a click for nothing.
                    Only where there is a party to bill.
                --}}
                @if ($table->currentSession)
                    @allows('pos.tables.view')
                        <a class="plan-table-bill"
                           href="{{ route('admin.table-bills.show', $table->currentSession) }}"
                           aria-label="Bill for table {{ $table->name }}">
                            <x-icon name="wallet" :size="13" /> Bill
                        </a>
                    @endallows
                @endif

                @allows('dining.tables.adjust')
                    {{--
                        The status control sits on the tile itself. Seating a
                        party and clearing a table are the two things done
                        most on this screen, and a modal in between is a modal
                        nobody opens at eight on a Saturday.
                    --}}
                    <form method="POST" action="{{ route('admin.tables.status', $table) }}"
                          data-ajax data-refresh-list class="plan-table-set">
                        @csrf
                        @method('PUT')
                        <label class="sr-only" for="plan-st-{{ $table->id }}">
                            Status for table {{ $table->name }}
                        </label>
                        <select id="plan-st-{{ $table->id }}" name="status"
                                onchange="this.form.requestSubmit()">
                            @foreach ($statuses as $key => $meta)
                                <option value="{{ $key }}" @selected($table->status === $key)>
                                    {{ $meta['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <noscript><button type="submit">Set</button></noscript>
                    </form>
                @endallows
            </div>
        @endforeach
    </div>

    @allows('dining.tables.adjust')
        <p class="text-xs text-muted plan-hint" style="padding: 10px 14px 0">
            The layout is locked. Press <strong>Edit layout</strong> above to drag tables; positions save as you drop them.
            @if (collect($positions)->contains('auto', true))
                Tables that have not been placed yet are shown in a tidy grid until you drag them into position.
            @endif
        </p>
    @endallows
@endif
