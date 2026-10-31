{{-- Swappable fragment: the demo-request inbox (§19). --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Received</th>
                <th>Who</th>
                <th>Business</th>
                <th>Contact</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-sm">
                        {{ $row->created_at?->format('j M, g:i a') }}
                        @if ($row->waitingHours() !== null)
                            {{-- Only on the unanswered: on a closed lead it
                                 would be a number nobody can act on. --}}
                            <span class="text-xs {{ $row->waitingHours() > 24 ? 'text-danger' : 'text-muted' }}"
                                  style="display:block">
                                waiting {{ $row->waitingHours() }}h
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        <strong>{{ $row->name }}</strong>
                        @if ($row->city)
                            <span class="text-xs text-muted" style="display:block">{{ $row->city }}</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $row->business_name ?: '—' }}
                        @if ($row->outlets)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $row->outlets }} {{ Str::plural('outlet', $row->outlets) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{-- Phone first: this sale closes on a call. --}}
                        @if ($row->phone)
                            <a href="tel:{{ preg_replace('/\s+/', '', $row->phone) }}">{{ $row->phone }}</a>
                        @endif
                        @if ($row->email)
                            <span class="text-xs text-muted" style="display:block">
                                <a href="mailto:{{ $row->email }}">{{ $row->email }}</a>
                            </span>
                        @endif
                    </td>

                    <td>
                        <span class="badge badge-{{ $row->statusTone() }}">{{ $row->statusLabel() }}</span>
                        @if ($row->handler)
                            <span class="text-xs text-muted" style="display:block">{{ $row->handler->name }}</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.demo-requests.show', $row) }}"
                               data-modal="{{ route('admin.demo-requests.show', $row) }}"
                               data-modal-title="Demo request"
                               data-modal-sub="{{ $row->name }}"
                               data-modal-size="lg"
                               aria-label="Open request from {{ $row->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.demo_requests.delete')
                                <form method="POST" action="{{ route('admin.demo-requests.destroy', $row) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete this enquiry from {{ addslashes($row->name) }}? Marking it closed keeps the record.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger" aria-label="Delete">
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
                            <x-icon name="inbox" :size="28" />
                            <h3>Nothing waiting</h3>
                            <p class="text-sm">
                                Enquiries from the “Book a demo” form on the landing page land here.
                                Choose “All” above to see the ones already dealt with.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rows" :per-page="$perPage" :page-sizes="$pageSizes" label="requests" />
