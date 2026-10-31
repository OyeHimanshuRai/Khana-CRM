{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Question</th>
                <th>Rule</th>
                <th>Answers</th>
                <th>Asked of</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($modifiers as $modifier)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.modifiers.show', $modifier) }}"
                               data-modal="{{ route('admin.modifiers.show', $modifier) }}"
                               data-modal-title="{{ $modifier->name }}"
                               data-modal-sub="Add-on detail">{{ $modifier->name }}</a>
                        </strong>

                        @if ($modifier->instruction)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $modifier->instruction }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $modifier->isRequired() ? 'badge-warning' : '' }}">
                            {{ $modifier->ruleLabel() }}
                        </span>
                    </td>

                    <td class="text-sm">
                        {{ number_format($modifier->options_count) }}

                        {{-- The first few answers, so the row says what the
                             question actually offers without opening it. --}}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $modifier->options->take(3)->pluck('name')->implode(', ') }}
                            @if ($modifier->options_count > 3)
                                +{{ $modifier->options_count - 3 }} more
                            @endif
                        </span>
                    </td>

                    <td class="text-sm">
                        @if ($modifier->products_count > 0)
                            {{ number_format($modifier->products_count) }} dish{{ $modifier->products_count === 1 ? '' : 'es' }}
                        @else
                            <span class="badge badge-danger"><span class="badge-dot"></span> No dish</span>
                        @endif
                    </td>

                    <td>
                        @allows('inventory.modifiers.edit')
                            <form method="POST" action="{{ route('admin.modifiers.status', $modifier) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $modifier->is_active ? 'is-on' : '' }}"
                                        title="{{ $modifier->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $modifier->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $modifier->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $modifier->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.modifiers.show', $modifier) }}"
                               data-modal="{{ route('admin.modifiers.show', $modifier) }}"
                               data-modal-title="{{ $modifier->name }}"
                               data-modal-sub="Add-on detail"
                               aria-label="View {{ $modifier->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.modifiers.edit')
                                <a class="btn btn-icon" href="{{ route('admin.modifiers.edit', $modifier) }}"
                                   data-modal="{{ route('admin.modifiers.edit', $modifier) }}"
                                   data-modal-title="Edit Add-on"
                                   data-modal-sub="{{ $modifier->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $modifier->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.modifiers.delete')
                                <form method="POST" action="{{ route('admin.modifiers.destroy', $modifier) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $modifier->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $modifier->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="help" :size="28" />
                            <h3>No add-ons yet</h3>
                            <p class="text-sm">
                                Define a question once — &ldquo;Choose your crust&rdquo; — and tick every
                                dish that asks it.
                            </p>
                            @allows('inventory.modifiers.create')
                                <a class="btn btn-primary btn-sm" href="{{ route('admin.modifiers.create') }}"
                                   data-modal="{{ route('admin.modifiers.create') }}"
                                   data-modal-title="New Add-on"
                                   data-modal-size="lg">Add the first one</a>
                            @endallows
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$modifiers" :per-page="$perPage" :page-sizes="$pageSizes" label="add-ons" />
