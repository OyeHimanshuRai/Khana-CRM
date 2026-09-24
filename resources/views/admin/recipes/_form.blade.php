{{--
    Edit one dish's recipe.

    No JavaScript. Existing rows plus six blank ones, and a blank row is
    skipped on the way in - so removing an ingredient means clearing its
    select, and adding one means filling a spare. A dish with more than six
    new ingredients is saved and reopened, which is rarer than a screen that
    needs a script to be usable at all.

    Quantities are in each ingredient's own unit, shown beside every box.
    There is no unit conversion anywhere in this system, and a field that
    silently meant grams while the shelf counted kilos is exactly the mistake
    that would cost a restaurant its stock figures.
--}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);

    // Existing components, then room for more.
    $blank = 6;
    $rows = $components->values();
    $unitOf = fn ($id) => optional($ingredients->firstWhere('id', $id))->unit?->code;
@endphp

<form method="POST" action="{{ route('admin.recipes.update', $dish) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    <input type="hidden" name="product_variant_id" value="{{ $variantId }}">

    @if ($ingredients->isEmpty())
        <p class="text-sm">
            This branch has no ingredients yet. Mark the things you buy — flour, butter,
            chicken — as ingredients on their menu-item page, and they will appear here.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
        </div>
    @else
        {{-- Sizes. A Full biryani uses more rice than a Half. --}}
        @if ($dish->variants->isNotEmpty())
            <div class="kds-tabs" style="margin-bottom:12px">
                <a class="kds-tab {{ $variantId === null ? 'is-on' : '' }}"
                   href="{{ route('admin.recipes.edit', $dish) }}"
                   data-modal="{{ route('admin.recipes.edit', $dish) }}"
                   data-modal-title="Recipe · {{ $dish->name }}"
                   data-modal-sub="All sizes">
                    All sizes
                </a>

                @foreach ($dish->variants as $variant)
                    <a class="kds-tab {{ (int) $variantId === (int) $variant->id ? 'is-on' : '' }}"
                       href="{{ route('admin.recipes.edit', [$dish, 'variant' => $variant->id]) }}"
                       data-modal="{{ route('admin.recipes.edit', [$dish, 'variant' => $variant->id]) }}"
                       data-modal-title="Recipe · {{ $dish->name }}"
                       data-modal-sub="{{ $variant->name }}">
                        {{ $variant->name }}
                    </a>
                @endforeach
            </div>

            @if ($inherited)
                <p class="text-xs" style="margin:0 0 10px; color:var(--warning)">
                    This size has no recipe of its own, so the all-sizes one is shown.
                    Saving here gives it one, and the all-sizes recipe will no longer
                    apply to it.
                </p>
            @endif
        @endif

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th style="width:130px">Quantity</th>
                        <th>Note</th>
                    </tr>
                </thead>

                <tbody>
                    @for ($i = 0; $i < $rows->count() + $blank; $i++)
                        @php $row = $rows->get($i); @endphp
                        <tr>
                            <td>
                                <label class="sr-only" for="rc-ing-{{ $i }}">Ingredient {{ $i + 1 }}</label>
                                <select id="rc-ing-{{ $i }}" name="rows[{{ $i }}][ingredient_id]"
                                        class="form-control" aria-invalid="false">
                                    {{-- Blank is how a row is removed. --}}
                                    <option value="">—</option>
                                    @foreach ($ingredients as $ingredient)
                                        <option value="{{ $ingredient->id }}"
                                            @selected($row && (int) $row->ingredient_id === (int) $ingredient->id)>
                                            {{ $ingredient->name }}
                                            @if ($ingredient->unit?->code) ({{ $ingredient->unit->code }}) @endif
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            <td>
                                <label class="sr-only" for="rc-qty-{{ $i }}">Quantity {{ $i + 1 }}</label>
                                <input id="rc-qty-{{ $i }}" type="number" name="rows[{{ $i }}][quantity]"
                                       class="form-control" min="0" step="0.0001" aria-invalid="false"
                                       value="{{ $row ? rtrim(rtrim(number_format((float) $row->quantity, 4, '.', ''), '0'), '.') : '' }}">

                                @if ($row?->ingredient?->unit?->code)
                                    <span class="text-xs text-muted">{{ $row->ingredient->unit->code }}</span>
                                @endif
                            </td>

                            <td>
                                <label class="sr-only" for="rc-note-{{ $i }}">Note {{ $i + 1 }}</label>
                                <input id="rc-note-{{ $i }}" type="text" name="rows[{{ $i }}][note]"
                                       class="form-control" maxlength="190" aria-invalid="false"
                                       value="{{ $row?->note }}" placeholder="Chopped fine">
                            </td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>

        @php $cost = $recipes->costOf($dish, $variantId, $shopId); @endphp

        @if ($cost !== null)
            <p class="text-sm" style="margin:10px 0 0">
                As it stands this
                {{ $variantId ? $dish->variants->firstWhere('id', $variantId)?->name : $dish->name }}
                costs <strong>{{ $money($cost) }}</strong> to make.
            </p>
        @endif

        <p class="text-xs text-muted" style="margin:6px 0 0">
            Quantities are per one of this dish, in each ingredient's own unit. Clearing a
            row's ingredient removes it. Leave every row blank to clear the recipe.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save recipe</button>
        </div>
    @endif
</form>
