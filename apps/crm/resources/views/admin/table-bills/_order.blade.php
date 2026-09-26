{{--
    Taking an order at the table (§6).

    The same card the guest sees on their phone, laid out for somebody holding
    a tablet instead: a section at a time, one small form per dish.

    One form per dish rather than one big one, because a dish carries its own
    size and its own questions and a single submit would need a naming scheme
    nobody can read. Each add refreshes the pad below it; the pad goes to the
    kitchen in one ticket when the captain presses Send.
--}}

@php
    use App\Models\Product;

    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

@if ($sections->isEmpty())
    <p class="text-sm">This branch has nothing on its menu yet.</p>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@else
    {{-- ------------------------------------------------------------ the pad --}}
    <div class="form-label">On the pad</div>

    @if ($picked->isEmpty())
        <p class="text-xs text-muted" style="margin:0 0 12px">
            Nothing yet. Add dishes below, then send the lot to the kitchen in one ticket.
        </p>
    @else
        <div class="table-wrap" style="margin-bottom:12px">
            <table class="table">
                <tbody>
                    @foreach ($picked as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->quantity }}×</strong> {{ $line->title() }}
                                @php $extras = $line->options(); @endphp
                                @if ($extras->isNotEmpty())
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $extras->pluck('name')->implode(', ') }}
                                    </span>
                                @endif
                                @if ($line->note)
                                    <span class="text-xs" style="display:block; color:var(--warning)">
                                        {{ $line->note }}
                                    </span>
                                @endif
                            </td>
                            <td style="text-align:right" class="text-sm">
                                {{ $money($line->lineTotal()) }}
                            </td>
                            <td class="col-action">
                                <form method="POST"
                                      action="{{ route('admin.table-bills.cart-remove', [$session, $line]) }}"
                                      data-ajax data-refresh-list>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $line->title() }}">
                                        <x-icon name="trash" :size="14" />
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.table-bills.send', $session) }}"
              data-ajax data-close-modal data-refresh-list style="margin-bottom:14px">
            @csrf
            <button type="submit" class="btn btn-primary" style="width:100%">
                Send {{ $picked->sum('quantity') }} item(s) to the kitchen
            </button>
        </form>
    @endif

    <hr style="border:0; border-top:1px solid var(--border); margin:0 0 14px">

    {{-- --------------------------------------------------------- the menu --}}
    @foreach ($sections as $section)
        <details class="staff-menu-section">
            <summary>
                {{ $section['category']->name }}
                <span class="text-xs text-muted">{{ $section['items']->count() }}</span>
            </summary>

            @foreach ($section['items'] as $dish)
                @php
                    // The same three reasons the guest's card greys a dish out.
                    $why = $dish->unavailableReason();
                @endphp

                <div class="staff-dish">
                    <div class="staff-dish-head">
                        <strong>{{ $dish->name }}</strong>
                        <span class="text-xs text-muted">{{ $menu->priceLabel($dish) }}</span>
                    </div>

                    @if ($why)
                        {{-- Said, not hidden: a captain asking why has to be
                             able to tell the guest. --}}
                        <p class="text-xs" style="margin:2px 0 0; color:var(--warning)">{{ $why }}</p>
                    @else
                        <form method="POST" action="{{ route('admin.table-bills.cart', $session) }}"
                              data-ajax data-refresh-list class="staff-dish-form">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $dish->id }}">

                            @if ($dish->variants->isNotEmpty())
                                <label class="sr-only" for="sz-{{ $dish->id }}">Size for {{ $dish->name }}</label>
                                <select id="sz-{{ $dish->id }}" name="product_variant_id" class="form-control" required>
                                    {{-- No blank option and no default guess: a
                                         caller that did not say which size did
                                         not ask the guest either. --}}
                                    @foreach ($dish->variants as $variant)
                                        <option value="{{ $variant->id }}" @selected($variant->is_default)>
                                            {{ $variant->name }} · {{ $money($variant->price) }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif

                            @foreach ($dish->modifiers as $modifier)
                                <div class="staff-dish-mods">
                                    <span class="text-xs text-muted">
                                        {{ $modifier->name }}
                                        @if ($modifier->min_select > 0) (required) @endif
                                    </span>
                                    @foreach ($modifier->options as $option)
                                        <label class="check text-sm">
                                            <input type="checkbox" name="options[]" value="{{ $option->id }}"
                                                   @disabled(! $option->is_active)>
                                            {{ $option->name }}
                                            @if ((float) $option->price > 0)
                                                +{{ $money($option->price) }}
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach

                            <div class="staff-dish-add">
                                <label class="sr-only" for="qt-{{ $dish->id }}">Quantity</label>
                                <input id="qt-{{ $dish->id }}" type="number" name="quantity"
                                       class="form-control" min="1" max="30" value="1">

                                <label class="sr-only" for="nt-{{ $dish->id }}">Note for the kitchen</label>
                                <input id="nt-{{ $dish->id }}" type="text" name="note" class="form-control"
                                       maxlength="190" placeholder="Less spicy, no onion…">

                                <button type="submit" class="btn btn-sm btn-primary">Add</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </details>
    @endforeach

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Done</button>
    </div>
@endif
