{{--
    What a dish is made of, read-only.

    Every size is shown, not only the default: a Full and a Half can have
    different recipes, and a screen that showed one of them would let a
    restaurant cost the other wrong for a year without noticing.
--}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);

    // All sizes first, then each size that has one of its own.
    $views = collect([['label' => 'All sizes', 'id' => null]]);

    foreach ($dish->variants as $variant) {
        $views->push(['label' => $variant->name, 'id' => $variant->id]);
    }
@endphp

@foreach ($views as $view)
    @php
        $components = $recipes->componentsFor($dish, $view['id'], $shopId);
        $cost = $recipes->costOf($dish, $view['id'], $shopId);

        // Whether this size is showing its own rows or the generic ones.
        $own = $view['id'] === null
            || $dish->recipeItems()->where('product_variant_id', $view['id'])->exists();
    @endphp

    @continue ($components->isEmpty() && $view['id'] !== null)

    <div style="margin-bottom:16px">
        <div class="form-label">
            {{ $view['label'] }}
            @if ($view['id'] !== null && ! $own)
                <span class="text-xs text-muted">— uses the all-sizes recipe</span>
            @endif
        </div>

        @if ($components->isEmpty())
            <p class="text-sm text-muted">No recipe yet.</p>
        @else
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($components as $item)
                            <tr>
                                <td>
                                    {{ $item->ingredient?->name ?? '—' }}
                                    @if ($item->note)
                                        <span class="text-xs text-muted" style="display:block">{{ $item->note }}</span>
                                    @endif
                                </td>
                                <td style="text-align:right" class="text-sm">{{ $item->label() }}</td>
                                <td style="text-align:right" class="text-sm">{{ $money($item->cost($shopId)) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Costs to make</th>
                            <th></th>
                            <th style="text-align:right">{{ $money($cost) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
@endforeach

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.recipes.edit')
        <a class="btn btn-primary" href="{{ route('admin.recipes.edit', $dish) }}"
           data-modal="{{ route('admin.recipes.edit', $dish) }}"
           data-modal-title="Recipe · {{ $dish->name }}"
           data-modal-sub="Quantities are in each ingredient's own unit">
            Edit
        </a>
    @endallows
</div>
