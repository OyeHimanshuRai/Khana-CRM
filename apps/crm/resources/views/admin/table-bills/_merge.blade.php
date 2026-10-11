{{--
    Move a ticket, or merge the whole table (§6).

    Two forms in one dialog because they are the same act at two sizes, and a
    cashier deciding between them wants to see both.

    The direction is stated, not inferred. The party sat down somewhere, and
    that is the table the runner will look for.
--}}

@php
    $label = fn ($s) => ($s->table?->code ?? '—')
        .' · '.$s->partyName()
        .($s->table?->floor?->name ? ' ('.$s->table->floor->name.')' : '');
@endphp

@if ($targets->isEmpty())
    <p class="text-sm">
        No other table is open. A ticket can only move to a table with a party at it.
    </p>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@else
    {{-- ------------------------------------------------------ one ticket --}}
    @if ($orders->isNotEmpty())
        <form method="POST" action="{{ route('admin.table-bills.move', $session) }}"
              data-ajax data-close-modal data-refresh-list>
            @csrf
            @method('PUT')

            <div class="form-label">Move one ticket</div>

            <div class="settings-grid">
                <div class="field">
                    <label for="mv-order">Ticket</label>
                    <select id="mv-order" name="order_id" class="form-control" required aria-invalid="false">
                        @foreach ($orders as $order)
                            <option value="{{ $order->id }}">
                                {{ $order->order_number }} — {{ $order->statusLabel() }}
                                (₹{{ number_format((float) $order->grand_total, 2) }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        The number stays as it is — it has already been called
                        out across the kitchen.
                    </div>
                </div>

                <div class="field">
                    <label for="mv-into">To table</label>
                    <select id="mv-into" name="into" class="form-control" required aria-invalid="false">
                        @foreach ($targets as $target)
                            <option value="{{ $target->id }}">{{ $label($target) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn">Move the ticket</button>
            </div>
        </form>

        <hr style="border:0; border-top:1px solid var(--border); margin:6px 0 14px">
    @endif

    {{-- ----------------------------------------------------- whole table --}}
    <form method="POST" action="{{ route('admin.table-bills.merge', $session) }}"
          data-ajax data-close-modal
          onsubmit="return confirm('Move everything from table {{ $session->table?->code }} onto the other table? This one will be closed.')">
        @csrf
        @method('PUT')

        <div class="form-label">Merge the whole table</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="mg-into">Put table {{ $session->table?->code ?? '—' }} onto</label>
                <select id="mg-into" name="into" class="form-control" required aria-invalid="false">
                    @foreach ($targets as $target)
                        <option value="{{ $target->id }}">{{ $label($target) }}</option>
                    @endforeach
                </select>
                <div class="form-hint">
                    Every ticket moves, and anything they have picked but not yet
                    sent goes with it. This table is then closed and freed.
                </div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Merge</button>
        </div>
    </form>
@endif
