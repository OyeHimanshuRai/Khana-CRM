{{-- Read-only detail, rendered straight into the modal body. --}}

<div class="cat-view-body">
    <div class="sec-name">{{ $modifier->name }}</div>

    @if ($modifier->instruction)
        <p class="text-sm text-muted" style="margin:2px 0 0">{{ $modifier->instruction }}</p>
    @endif

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:10px 0 14px">
        <span class="badge {{ $modifier->isRequired() ? 'badge-warning' : '' }}">
            {{ $modifier->ruleLabel() }}
        </span>

        <span class="badge {{ $modifier->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $modifier->is_active ? 'Active' : 'Inactive' }}
        </span>

        <span class="badge badge-info">
            {{ $modifier->options->count() }} answer{{ $modifier->options->count() === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="form-label">Answers</div>

    <div class="table-wrap" style="margin-top:6px">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Price change</th>
                    <th>Default</th>
                    <th>Available</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($modifier->options as $option)
                    <tr>
                        <td>{{ $option->name }}</td>
                        <td class="text-sm">{{ $option->priceLabel() ?: 'No change' }}</td>
                        <td class="text-sm">{{ $option->is_default ? 'Yes' : '—' }}</td>
                        <td>
                            @if ($option->is_available)
                                <span class="badge badge-success"><span class="badge-dot"></span> Yes</span>
                            @else
                                <span class="badge badge-danger"><span class="badge-dot"></span> Off</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="form-label" style="margin-top:16px">Asked of</div>

    @if ($modifier->products->isEmpty())
        <p class="text-sm text-muted">
            No dish asks this yet — so nobody is ever shown it. Edit the add-on to tick some.
        </p>
    @else
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
            @foreach ($modifier->products as $product)
                <span class="badge">{{ $product->name }}</span>
            @endforeach
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.modifiers.edit')
        <a class="btn btn-primary" href="{{ route('admin.modifiers.edit', $modifier) }}"
           data-modal="{{ route('admin.modifiers.edit', $modifier) }}"
           data-modal-title="Edit Add-on"
           data-modal-sub="{{ $modifier->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
