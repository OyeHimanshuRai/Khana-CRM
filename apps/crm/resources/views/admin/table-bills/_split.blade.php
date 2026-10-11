{{--
    Split the bill (§6).

    Choose what goes on this bill; the rest stays on the table for the next
    one. A quantity per line rather than a tick, because "three of the five
    beers are mine" is a real split and a checkbox cannot say it.

    The quantity boxes default to zero, not to the full amount. A split that
    started with everything selected is a split where one careless Enter bills
    the whole table to whoever is holding the card machine.
--}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $lines = $summary['lines'];
@endphp

<form method="POST" action="{{ route('admin.table-bills.settle', $session) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    @if ($lines->isEmpty())
        <p class="text-sm">There is nothing left to bill on this table.</p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align:right">Left</th>
                        <th style="text-align:right">Price</th>
                        <th style="width:110px">On this bill</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($lines as $i => $line)
                        <tr>
                            <td>
                                {{ $line->title() }}
                                @if ($line->modifiers->isNotEmpty())
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $line->modifiers->map(fn ($m) => $m->option_name)->implode(', ') }}
                                    </span>
                                @endif

                                <input type="hidden" name="lines[{{ $i }}][order_item_id]" value="{{ $line->id }}">
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ $qty($line->unsettledQuantity()) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ $money($line->unit_price) }}
                            </td>

                            <td>
                                <label class="sr-only" for="sp-qty-{{ $line->id }}">
                                    How much of {{ $line->title() }} goes on this bill
                                </label>
                                <input id="sp-qty-{{ $line->id }}" type="number"
                                       name="lines[{{ $i }}][quantity]" class="form-control"
                                       min="0" max="{{ $line->unsettledQuantity() }}" step="0.001"
                                       value="0" aria-invalid="false">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="settings-grid" style="margin-top:12px">
            @foreach ([0, 1] as $i)
                <div class="field">
                    <label for="sp-method-{{ $i }}">
                        {{ $i === 0 ? 'Payment' : 'Second payment (optional)' }}
                    </label>
                    <select id="sp-method-{{ $i }}" name="payments[{{ $i }}][method]" class="form-control">
                        @foreach (App\Models\Payment::METHODS as $key => $meta)
                            <option value="{{ $key }}" @selected($key === 'cash')>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>

                    <label class="sr-only" for="sp-amount-{{ $i }}">Amount</label>
                    <input id="sp-amount-{{ $i }}" type="number" name="payments[{{ $i }}][amount]"
                           class="form-control" min="0" step="0.01" style="margin-top:6px" placeholder="0.00">
                </div>
            @endforeach
        </div>

        <p class="text-xs text-muted" style="margin:10px 0 0">
            Whatever is left stays on the table and can be billed separately.
            The table closes when the last of it is paid for.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Raise this bill</button>
        </div>
    @endif
</form>
