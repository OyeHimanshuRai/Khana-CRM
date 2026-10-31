{{--
    Swappable fragment: the table plus its pagination.

    The Shop column only appears in All-shops mode - inside one shop it would
    be the same value on every row.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Area</th>
                <th>Code</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Tables</th>
                <th>Seats</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($floors as $floor)
                <tr>
                    <td>
                        <strong>{{ $floor->name }}</strong>
                        @if ($floor->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($floor->description, 60) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $floor->code }}</span></td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $floor->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">
                        @allows('dining.tables.view')
                            <a href="{{ route('admin.tables.index', ['floor_id' => $floor->id]) }}">
                                {{ number_format($floor->tables_count) }}
                            </a>
                        @else
                            {{ number_format($floor->tables_count) }}
                        @endallows

                        {{-- Only worth saying when the two numbers differ. --}}
                        @if ($floor->tables_count !== $floor->active_tables_count)
                            <span class="text-xs text-muted" style="display:block">
                                {{ number_format($floor->active_tables_count) }} in service
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">{{ number_format((int) $floor->seats) }}</td>

                    <td>
                        @allows('dining.floors.edit')
                            <form method="POST" action="{{ route('admin.floors.status', $floor) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $floor->is_active ? 'is-on' : '' }}"
                                        title="{{ $floor->is_active ? 'Click to close this area' : 'Click to open this area' }}">
                                    <span class="badge-dot"></span>
                                    {{ $floor->is_active ? 'Open' : 'Closed' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $floor->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $floor->is_active ? 'Open' : 'Closed' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('dining.tables.view')
                                <a class="btn btn-icon"
                                   href="{{ route('admin.tables.plan', ['floor_id' => $floor->id]) }}"
                                   title="Open the floor plan"
                                   aria-label="Floor plan for {{ $floor->name }}">
                                    <x-icon name="grid" :size="15" />
                                </a>
                            @endallows

                            @allows('dining.floors.edit')
                                <a class="btn btn-icon" href="{{ route('admin.floors.edit', $floor) }}"
                                   data-modal="{{ route('admin.floors.edit', $floor) }}"
                                   data-modal-title="Edit Dining Area"
                                   data-modal-sub="{{ $floor->name }}"
                                   aria-label="Edit {{ $floor->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('dining.floors.delete')
                                <form method="POST" action="{{ route('admin.floors.destroy', $floor) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $floor->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $floor->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 7 : 6 }}">
                        <div class="empty">
                            <x-icon name="building" :size="28" />
                            <h3>No dining areas yet</h3>
                            <p class="text-sm">
                                Every table belongs to one. Add the areas guests sit in, then add their tables.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$floors" :per-page="$perPage" :page-sizes="$pageSizes" label="areas" />
