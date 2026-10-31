{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change - so every write in this
    module ends with a data-refresh-list that re-fetches exactly this.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Image</th>
                <th>Title</th>
                <th>Name</th>
                <th>Timing</th>
                <th>From</th>
                <th>To</th>
                <th>Booth No</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($events as $event)
                @php $image = $event->imageUrl(); @endphp
                <tr>
                    <td>
                        <span class="slider-thumb">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $event->title }}">
                            @else
                                <x-icon name="calendar" :size="16" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.events.show', $event) }}"
                               data-modal="{{ route('admin.events.show', $event) }}"
                               data-modal-title="{{ $event->title }}"
                               data-modal-sub="Event details"
                               data-modal-size="lg">{{ $event->title }}</a>
                        </strong>
                        {{-- Where the event sits on the calendar, which is a
                             separate thing from the on/off switch. --}}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $event->phaseLabel() }}
                        </span>
                    </td>

                    <td class="text-sm text-muted">{{ $event->name ?: '—' }}</td>

                    <td class="text-sm text-muted" style="white-space:nowrap">
                        {{ $event->timing ?: '—' }}
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $event->from_date?->format('d M Y') ?? '—' }}
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $event->to_date?->format('d M Y') ?? '—' }}
                    </td>

                    <td class="text-sm">
                        @if ($event->booth_no)
                            <span class="list-ref">{{ $event->booth_no }}</span>
                        @else
                            <span class="text-xs text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        @allows('content.events.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.events.status', $event) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $event->is_active ? 'is-on' : '' }}"
                                        title="{{ $event->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $event->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $event->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $event->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.events.show', $event) }}"
                               data-modal="{{ route('admin.events.show', $event) }}"
                               data-modal-title="{{ $event->title }}"
                               data-modal-sub="Event details"
                               data-modal-size="lg"
                               aria-label="View {{ $event->title }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.events.edit')
                                <a class="btn btn-icon" href="{{ route('admin.events.edit', $event) }}"
                                   data-modal="{{ route('admin.events.edit', $event) }}"
                                   data-modal-title="Edit Event"
                                   data-modal-sub="{{ $event->title }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $event->title }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.events.delete')
                                <form method="POST" action="{{ route('admin.events.destroy', $event) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $event->title }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $event->title }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No events found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$events" :per-page="$perPage" :page-sizes="$pageSizes" label="events" />
