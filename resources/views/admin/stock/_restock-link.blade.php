{{--
    The "Restock" link shown where an item is out of stock or running low - the
    moment somebody is looking for it. A text link rather than an icon, because
    what it does is not guessable from a glyph, and it only appears where it is
    useful: for an item that has a shelf, to someone who may add to it.

    Expects: $product.
--}}
@if ($product->tracksStock())
    @allows('inventory.stock.restock')
        <a class="restock-link" href="{{ route('admin.stock.restock.form', $product) }}"
           data-modal="{{ route('admin.stock.restock.form', $product) }}"
           data-modal-title="Restock"
           data-modal-sub="{{ $product->name }}"
           data-modal-size="sm"
           aria-label="Restock {{ $product->name }}">Restock</a>
    @endallows
@endif
