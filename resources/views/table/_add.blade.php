{{--
    The Add control for one dish.

    Deliberately a plain form, and it stays one: a <details> gives the
    disclosure, radios and checkboxes give the choices, and the server does
    the arithmetic. This runs on whatever phone a guest walked in with, on
    restaurant wifi, and a menu that needs a bundle to download before anybody
    can order is a menu that fails at the moment it matters.

    data-ajax only upgrades it. Where the script arrived, the post goes
    through fetch and the answer comes back as a toast, so a guest adding four
    dishes keeps their place on the card instead of being thrown to the top of
    the menu four times; the cart bar is redrawn from the count the server
    sends back. Where it did not arrive, the browser posts the form itself and
    the page comes back with a flash on it, exactly as it always has.

    Dishes with no sizes and no questions get a bare Add button, because that
    is most of a card and it should be one tap.
--}}

@php
    $needsChoices = $item->variants->isNotEmpty() || $item->modifiers->isNotEmpty();
@endphp

<form method="POST" action="{{ route('table.cart.add') }}" class="t-add"
      data-ajax data-busy="Adding…">
    @csrf
    <input type="hidden" name="product_id" value="{{ $item->id }}">

    @if (! $needsChoices)
        <button type="submit" class="t-add-btn">Add</button>
    @else
        <details class="t-choices">
            <summary class="t-add-btn t-add-btn-open">Choose &amp; add</summary>

            @if ($item->variants->isNotEmpty())
                {{--
                    A fieldset, so a screen reader announces "Size" before the
                    options rather than reading five prices with no question.
                --}}
                <fieldset class="t-group">
                    <legend class="t-group-title">Size</legend>

                    @foreach ($item->variants as $variant)
                        <label class="t-opt @unless ($variant->is_available) is-off @endunless">
                            <input type="radio" name="product_variant_id" value="{{ $variant->id }}"
                                   @checked($variant->is_default)
                                   @disabled(! $variant->is_available)
                                   required>
                            <span class="t-opt-name">{{ $variant->name }}</span>
                            <span class="t-opt-price">₹{{ number_format((float) $variant->price, 0) }}</span>
                            @unless ($variant->is_available)
                                <span class="t-opt-off">Not available</span>
                            @endunless
                        </label>
                    @endforeach
                </fieldset>
            @endif

            @foreach ($item->modifiers as $question)
                <fieldset class="t-group">
                    <legend class="t-group-title">
                        {{ $question->name }}
                        <span class="t-group-rule">{{ $question->ruleLabel() }}</span>
                    </legend>

                    @if ($question->instruction)
                        <p class="t-group-hint">{{ $question->instruction }}</p>
                    @endif

                    @foreach ($question->options as $option)
                        <label class="t-opt @unless ($option->is_available) is-off @endunless">
                            {{--
                                Checkboxes throughout, even for a pick-one
                                question. Radios would need a distinct `name`
                                per question, and the server takes one flat
                                `options[]` list - so the count rule is
                                enforced in one place rather than half in the
                                browser's radio behaviour. Modifier::accepts()
                                refuses two crusts either way.
                            --}}
                            <input type="checkbox" name="options[]" value="{{ $option->id }}"
                                   @checked($option->is_default && $option->is_available)
                                   @disabled(! $option->is_available)>
                            <span class="t-opt-name">{{ $option->name }}</span>
                            @if ($option->priceLabel())
                                <span class="t-opt-price">{{ $option->priceLabel() }}</span>
                            @endif
                            @unless ($option->is_available)
                                <span class="t-opt-off">Not available</span>
                            @endunless
                        </label>
                    @endforeach
                </fieldset>
            @endforeach

            <div class="t-group">
                <label class="t-group-title" for="note-{{ $item->id }}">Anything to tell the kitchen?</label>
                <input id="note-{{ $item->id }}" type="text" name="note" class="t-input"
                       maxlength="250" autocomplete="off"
                       placeholder="Less oil, no coriander…">
            </div>

            <div class="t-add-row">
                <label class="sr-only" for="qty-{{ $item->id }}">Quantity</label>
                <input id="qty-{{ $item->id }}" type="number" name="quantity" class="t-qty"
                       value="1" min="1" max="30" inputmode="numeric">

                <button type="submit" class="t-add-btn">Add to order</button>
            </div>
        </details>
    @endif
</form>
