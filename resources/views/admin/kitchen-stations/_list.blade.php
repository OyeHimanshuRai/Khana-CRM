{{--
    Swappable fragment: the table plus its pagination.

    The Shop column only appears in All-shops mode - inside one shop it would
    be the same value on every row.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Station</th>
                <th>Code</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Routed here</th>
                <th>Allowed</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($stations as $station)
                <tr>
                    <td>
                        <strong>{{ $station->name }}</strong>

                        @if ($station->is_default)
                            <span class="badge badge-brand">Default</span>
                        @endif

                        @if ($station->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($station->description, 60) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $station->code }}</span></td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $station->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">
                        {{--
                            Two numbers, because they are set in two places
                            and somebody auditing their routing needs to know
                            which one to go and change.
                        --}}
                        {{ number_format($station->categories_count) }}
                        {{ Str::plural('section', $station->categories_count) }}

                        @if ($station->products_count > 0)
                            <span class="text-xs text-muted" style="display:block">
                                + {{ number_format($station->products_count) }}
                                {{ Str::plural('dish', $station->products_count) }} set directly
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $station->prepMinutes() }} min
                        <span class="text-xs text-muted" style="display:block">before it reads late</span>
                    </td>

                    <td>
                        @allows('kitchen.stations.edit')
                            <form method="POST" action="{{ route('admin.kitchen-stations.status', $station) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $station->is_active ? 'is-on' : '' }}"
                                        title="{{ $station->is_active ? 'Click to close this station' : 'Click to open this station' }}">
                                    <span class="badge-dot"></span>
                                    {{ $station->is_active ? 'Open' : 'Closed' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $station->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $station->is_active ? 'Open' : 'Closed' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('kitchen.tickets.view')
                                <a class="btn btn-icon"
                                   href="{{ route('admin.kitchen.index', ['station' => $station->id]) }}"
                                   title="Open this station's board"
                                   aria-label="Kitchen display for {{ $station->name }}">
                                    <x-icon name="zap" :size="15" />
                                </a>
                            @endallows

                            @allows('kitchen.stations.edit')
                                @unless ($station->is_default)
                                    <form method="POST" action="{{ route('admin.kitchen-stations.default', $station) }}"
                                          data-ajax data-refresh-list style="display:inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Make this the default station"
                                                aria-label="Make {{ $station->name }} the default station">
                                            <x-icon name="star" :size="15" />
                                        </button>
                                    </form>
                                @endunless

                                <a class="btn btn-icon" href="{{ route('admin.kitchen-stations.edit', $station) }}"
                                   data-modal="{{ route('admin.kitchen-stations.edit', $station) }}"
                                   data-modal-title="Edit Kitchen Station"
                                   data-modal-sub="{{ $station->name }}"
                                   aria-label="Edit {{ $station->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('kitchen.stations.delete')
                                <form method="POST" action="{{ route('admin.kitchen-stations.destroy', $station) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $station->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $station->name }}">
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
                            <x-icon name="tool" :size="28" />
                            <h3>No kitchen stations yet</h3>
                            <p class="text-sm">
                                A one-room kitchen does not need any — every ticket goes to one screen.
                                Add stations when the bar, the tandoor or the bakery want a board of their own.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$stations" :per-page="$perPage" :page-sizes="$pageSizes" label="stations" />
