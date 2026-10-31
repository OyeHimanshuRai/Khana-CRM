{{--
    Every dish, with what it costs to make and what it sells for.

    The margin column is the reason this screen is a list rather than a tab on
    each product: "which of my dishes am I not making money on" is a
    comparative question, and answering it forty clicks at a time is not
    answering it.
--}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Dish</th>
                <th>Ingredients</th>
                <th style="text-align:right">Costs to make</th>
                <th style="text-align:right">Sells for</th>
                <th style="text-align:right">Margin</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($dishes as $dish)
                @php
                    // From the page's cost map - one query for the lot, rather
                    // than one per row.
                    $cost = $costs[$dish->id] ?? null;
                    $price = (float) $dish->sellingPriceFor($shopId);
                    $margin = $cost !== null && $price > 0
                        ? ($price - $cost) / $price * 100
                        : null;
                @endphp

                <tr>
                    <td>
                        <strong>{{ $dish->name }}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $dish->category?->name ?? 'Unfiled' }}
                            @unless ($dish->is_made_to_order)
                                · stocked, not cooked
                            @endunless
                        </span>
                    </td>

                    <td class="text-sm">
                        @if ($dish->recipe_items_count > 0)
                            {{ number_format($dish->recipe_items_count) }}
                            {{ Str::plural('ingredient', $dish->recipe_items_count) }}

                            {{-- Sizes are worth calling out: a Full biryani uses
                                 more rice than a Half, and each can have its
                                 own recipe. --}}
                            @if ($dish->variants->isNotEmpty())
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $dish->variants->count() }} size(s)
                                </span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td style="text-align:right" class="text-sm">
                        {{-- Null, not zero: a dish with no recipe is un-costed,
                             and "₹0.00" would read as free. --}}
                        {{ $cost === null ? '—' : $money($cost) }}
                    </td>

                    <td style="text-align:right" class="text-sm">{{ $money($price) }}</td>

                    <td style="text-align:right">
                        @if ($margin === null)
                            <span class="text-muted">—</span>
                        @else
                            {{-- A kitchen runs 25-35% food cost, so anything
                                 under 50% margin is worth a colour. --}}
                            <strong style="color:var({{ $margin < 50 ? '--danger' : '--success' }})">
                                {{ number_format($margin, 1) }}%
                            </strong>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @if ($dish->recipe_items_count > 0)
                                <a class="btn btn-icon" href="{{ route('admin.recipes.show', $dish) }}"
                                   data-modal="{{ route('admin.recipes.show', $dish) }}"
                                   data-modal-title="{{ $dish->name }}"
                                   data-modal-sub="What it is made of"
                                   aria-label="Recipe for {{ $dish->name }}">
                                    <x-icon name="search" :size="15" />
                                </a>
                            @endif

                            @allows('inventory.recipes.edit')
                                <a class="btn btn-icon" href="{{ route('admin.recipes.edit', $dish) }}"
                                   data-modal="{{ route('admin.recipes.edit', $dish) }}"
                                   data-modal-title="Recipe · {{ $dish->name }}"
                                   data-modal-sub="Quantities are in each ingredient's own unit"
                                   aria-label="Edit the recipe for {{ $dish->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="package" :size="28" />
                            <h3>No dishes to cost</h3>
                            <p class="text-sm">
                                A recipe turns a dish into the flour and butter it is
                                made of. Add menu items first, then mark the things you
                                buy as ingredients.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$dishes" :per-page="$perPage" :page-sizes="$pageSizes" label="dishes" />
