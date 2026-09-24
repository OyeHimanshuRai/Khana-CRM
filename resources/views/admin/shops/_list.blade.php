{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change.
--}}

@php $currentShopId = App\Support\CurrentShop::id(); @endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Logo</th>
                <th>Shop</th>
                <th>Code</th>
                <th>Location</th>
                <th>Modules</th>
                <th>Staff</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($shops as $shop)
                @php $logo = $shop->logoUrl(); @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $shop->name }}">
                            @else
                                {{ $shop->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.shops.show', $shop) }}"
                               data-modal="{{ route('admin.shops.show', $shop) }}"
                               data-modal-title="{{ $shop->name }}"
                               data-modal-sub="Shop details"
                               data-modal-size="lg">{{ $shop->name }}</a>
                        </strong>

                        @if ($shop->id === $currentShopId)
                            {{-- Which till the reader is standing at, so a
                                 destructive action here is never a surprise. --}}
                            <span class="badge badge-brand" style="margin-left:6px">Current</span>
                        @endif

                        @if ($shop->legal_name)
                            <span class="text-xs text-muted" style="display:block">{{ $shop->legal_name }}</span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $shop->code }}</span></td>

                    <td class="text-sm">
                        {{ collect([$shop->city, $shop->state])->filter()->implode(', ') ?: '—' }}
                    </td>

                    {{-- What the branch trades in, which is more use at a
                         glance than its GSTIN. The full list is on the
                         detail modal. --}}
                    <td class="text-sm">
                        @php $labels = $shop->moduleLabels(); @endphp

                        @if (empty($labels))
                            <span class="badge badge-danger">None</span>
                        @else
                            {{ implode(', ', array_slice($labels, 0, 3)) }}
                            @if (count($labels) > 3)
                                <span class="text-xs text-muted">
                                    +{{ count($labels) - 3 }} more
                                </span>
                            @endif
                        @endif
                    </td>

                    <td class="text-sm">{{ number_format($shop->users_count) }}</td>

                    <td>
                        @allows('settings.shops.edit')
                            <form method="POST" action="{{ route('admin.shops.status', $shop) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $shop->is_active ? 'is-on' : '' }}"
                                        title="{{ $shop->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $shop->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $shop->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $shop->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.shops.show', $shop) }}"
                               data-modal="{{ route('admin.shops.show', $shop) }}"
                               data-modal-title="{{ $shop->name }}"
                               data-modal-sub="Shop details"
                               data-modal-size="lg"
                               aria-label="View {{ $shop->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('settings.shops.edit')
                                <a class="btn btn-icon" href="{{ route('admin.shops.edit', $shop) }}"
                                   data-modal="{{ route('admin.shops.edit', $shop) }}"
                                   data-modal-title="Edit Shop"
                                   data-modal-sub="{{ $shop->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $shop->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('settings.shops.delete')
                                <form method="POST" action="{{ route('admin.shops.destroy', $shop) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove “{{ $shop->name }}”? Its invoices, payments and stock history are kept, but nobody will be able to work in it.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $shop->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No shops found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$shops" :per-page="$perPage" :page-sizes="$pageSizes" label="shops" />
