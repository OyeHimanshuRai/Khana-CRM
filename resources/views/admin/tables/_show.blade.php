{{-- Read-only detail, rendered straight into the modal body. --}}

<div class="cat-view-body">
    <div class="sec-name">
        Table {{ $table->name }}
        <span class="list-ref">{{ $table->code }}</span>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $table->statusTone() ? 'badge-'.$table->statusTone() : '' }}">
            <span class="badge-dot"></span> {{ $table->statusLabel() }}
        </span>

        <span class="badge {{ $table->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $table->is_active ? 'In service' : 'Out of service' }}
        </span>

        <span class="badge badge-info">{{ $table->floor?->name ?? 'No area' }}</span>

        @if (App\Support\CurrentShop::id() === null)
            <span class="badge">{{ $table->shop?->name ?? 'No outlet' }}</span>
        @endif
    </div>

    <dl class="sec-facts">
        <div><dt>Seats</dt><dd>{{ number_format($table->capacity) }}</dd></div>
        <div><dt>Area</dt><dd>{{ $table->floor?->name ?? '—' }}</dd></div>
        <div>
            <dt>QR code</dt>
            <dd>{{ $table->activeQr ? 'Issued '.$table->activeQr->issued_at?->format('d M Y') : 'None' }}</dd>
        </div>
        <div>
            <dt>Scans</dt>
            <dd>{{ $table->activeQr ? number_format($table->activeQr->scan_count) : '—' }}</dd>
        </div>
        <div>
            <dt>Last scanned</dt>
            <dd>{{ $table->activeQr?->last_scanned_at?->diffForHumans() ?? 'Never' }}</dd>
        </div>
        <div><dt>Added</dt><dd>{{ $table->created_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>

    @if ($table->note)
        <div style="margin-top:16px">
            <div class="form-label">Note</div>
            <p class="text-sm text-muted">{{ $table->note }}</p>
        </div>
    @endif

    {{--
        The code history. Worth showing because "why did this table's QR
        change" is a question that gets asked, and the answer is only useful
        with a date and a name against it.
    --}}
    @if ($table->qrs->count() > 1)
        <div style="margin-top:18px">
            <div class="form-label">QR history</div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Issued</th>
                            <th>By</th>
                            <th>Withdrawn</th>
                            <th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table->qrs as $qr)
                            <tr>
                                <td class="text-sm">{{ $qr->issued_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="text-sm">{{ $qr->issuer?->name ?? 'System' }}</td>
                                <td class="text-sm">
                                    @if ($qr->isLive())
                                        <span class="badge badge-success"><span class="badge-dot"></span> Live</span>
                                    @else
                                        {{ $qr->revoked_at?->format('d M Y H:i') }}
                                    @endif
                                </td>
                                <td class="text-sm text-muted">{{ $qr->revoked_reason ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('dining.qr.view')
        <a class="btn" href="{{ route('admin.qr.show', $table) }}"
           data-modal="{{ route('admin.qr.show', $table) }}"
           data-modal-title="QR — Table {{ $table->name }}"
           data-modal-sub="{{ $table->fullName() }}">
            <x-icon name="scan" :size="15" /> QR code
        </a>
    @endallows

    @allows('dining.tables.edit')
        <a class="btn btn-primary" href="{{ route('admin.tables.edit', $table) }}"
           data-modal="{{ route('admin.tables.edit', $table) }}"
           data-modal-title="Edit Table"
           data-modal-sub="{{ $table->fullName() }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
