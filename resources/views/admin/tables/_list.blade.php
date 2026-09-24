{{--
    Swappable fragment: the table plus its pagination.

    Status is a control rather than a badge for anybody holding
    dining.tables.adjust - it is the thing changed most often on this screen,
    and making people open a modal for it would have them stop using it.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Table</th>
                <th>Code</th>
                <th>Area</th>
                @if ($showsShop)<th>Outlet</th>@endif
                <th>Seats</th>
                <th>Status</th>
                <th>QR</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tables as $table)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.tables.show', $table) }}"
                               data-modal="{{ route('admin.tables.show', $table) }}"
                               data-modal-title="Table {{ $table->name }}"
                               data-modal-sub="{{ $table->fullName() }}">{{ $table->name }}</a>
                        </strong>

                        @unless ($table->is_active)
                            <span class="badge badge-danger" style="margin-left:6px">Out of service</span>
                        @endunless

                        @if ($table->currentSession)
                            <span class="text-xs text-muted" style="display:block">
                                Seated {{ $table->currentSession->seatedMinutes() }} min
                            </span>
                        @elseif ($table->note)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($table->note, 48) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $table->code }}</span></td>

                    <td class="text-sm">{{ $table->floor?->name ?? '—' }}</td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $table->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ number_format($table->capacity) }}</td>

                    <td>
                        @allows('dining.tables.adjust')
                            <form method="POST" action="{{ route('admin.tables.status', $table) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <label class="sr-only" for="st-{{ $table->id }}">
                                    Status for table {{ $table->name }}
                                </label>
                                {{-- Submits on change; the button is the no-JS path. --}}
                                <select id="st-{{ $table->id }}" name="status" class="form-control"
                                        onchange="this.form.requestSubmit()">
                                    @foreach ($statuses as $key => $meta)
                                        <option value="{{ $key }}" @selected($table->status === $key)>
                                            {{ $meta['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <noscript><button type="submit" class="btn btn-sm">Set</button></noscript>
                            </form>

                            {{--
                                The sitting, when the flag above disagrees with it.

                                The select edits a flag somebody sets by hand; the
                                sitting is a bill with people attached. A table
                                reading "Cleaning" here while Table Bills showed the
                                same table seated with money owed is how two parties
                                end up on one tab - so where they differ, this says
                                which one owns a bill.
                            --}}
                            @if ($table->currentSession && $table->status !== $table->effectiveStatus())
                                <span class="text-xs" style="display:block;color:var(--warning)">
                                    Really {{ $table->statusLabel() }} —
                                    <a href="{{ route('admin.table-bills.show', $table->currentSession) }}">{{ $table->currentSession->partyName() }}</a>
                                    is still sitting here
                                </span>
                            @endif
                        @else
                            <span class="badge {{ $table->statusTone() ? 'badge-'.$table->statusTone() : '' }}">
                                <span class="badge-dot"></span>
                                {{ $table->statusLabel() }}
                            </span>
                        @endallows
                    </td>

                    <td>
                        @if ($table->activeQr)
                            <span class="badge badge-success"><span class="badge-dot"></span> Issued</span>
                        @else
                            <span class="badge badge-danger"><span class="badge-dot"></span> None</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.tables.show', $table) }}"
                               data-modal="{{ route('admin.tables.show', $table) }}"
                               data-modal-title="Table {{ $table->name }}"
                               data-modal-sub="{{ $table->fullName() }}"
                               aria-label="View table {{ $table->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('dining.qr.view')
                                <a class="btn btn-icon" href="{{ route('admin.qr.show', $table) }}"
                                   data-modal="{{ route('admin.qr.show', $table) }}"
                                   data-modal-title="QR — Table {{ $table->name }}"
                                   data-modal-sub="{{ $table->fullName() }}"
                                   aria-label="QR code for table {{ $table->name }}">
                                    <x-icon name="scan" :size="15" />
                                </a>
                            @endallows

                            @allows('dining.tables.edit')
                                <a class="btn btn-icon" href="{{ route('admin.tables.edit', $table) }}"
                                   data-modal="{{ route('admin.tables.edit', $table) }}"
                                   data-modal-title="Edit Table"
                                   data-modal-sub="{{ $table->fullName() }}"
                                   aria-label="Edit table {{ $table->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>

                                <form method="POST" action="{{ route('admin.tables.service', $table) }}"
                                      data-ajax data-refresh-list style="display:inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-icon"
                                            title="{{ $table->is_active ? 'Take out of service' : 'Put back in service' }}"
                                            aria-label="{{ $table->is_active ? 'Take table '.$table->name.' out of service' : 'Put table '.$table->name.' back in service' }}">
                                        <x-icon name="{{ $table->is_active ? 'user-x' : 'user-check' }}" :size="15" />
                                    </button>
                                </form>
                            @endallows

                            @allows('dining.tables.delete')
                                <form method="POST" action="{{ route('admin.tables.destroy', $table) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete table “{{ $table->name }}”? Its QR code stops working immediately and this cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete table {{ $table->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 8 : 7 }}">
                        <div class="empty">
                            <x-icon name="grid" :size="28" />
                            <h3>No tables found</h3>
                            <p class="text-sm">
                                Adjust the filters, or add a table — it gets its QR code the moment it is created.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$tables" :per-page="$perPage" :page-sizes="$pageSizes" label="tables" />
