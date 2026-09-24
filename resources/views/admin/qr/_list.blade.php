{{--
    Swappable fragment.

    Not paginated, deliberately: this list is read against the room, table by
    table, and a restaurant with sixty tables wants to see sixty rows rather
    than page through them looking for the one whose sticker is peeling.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Table</th>
                <th>Area</th>
                <th>Code</th>
                <th>Issued</th>
                <th>Scans</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tables as $table)
                <tr>
                    <td><strong>{{ $table->name }}</strong></td>

                    <td class="text-sm">{{ $table->floor?->name ?? '—' }}</td>

                    <td><span class="list-ref">{{ $table->code }}</span></td>

                    <td class="text-sm">
                        @if ($table->activeQr)
                            {{ $table->activeQr->issued_at?->format('d M Y') ?? '—' }}
                        @else
                            <span class="badge badge-danger"><span class="badge-dot"></span> No code</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($table->activeQr)
                            {{ number_format($table->activeQr->scan_count) }}
                            @if ($table->activeQr->last_scanned_at)
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $table->activeQr->last_scanned_at->diffForHumans() }}
                                </span>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.qr.show', $table) }}"
                               data-modal="{{ route('admin.qr.show', $table) }}"
                               data-modal-title="QR — Table {{ $table->name }}"
                               data-modal-sub="{{ $table->fullName() }}"
                               aria-label="Show the QR code for table {{ $table->name }}">
                                <x-icon name="scan" :size="15" />
                            </a>

                            @allows('dining.qr.create')
                                <form method="POST" action="{{ route('admin.qr.regenerate', $table) }}"
                                      data-ajax data-refresh-list style="display:inline"
                                      onsubmit="return confirm('{{ $table->activeQr
                                          ? 'Give table “'.$table->name.'” a new QR code? Every sticker already on that table stops working immediately.'
                                          : 'Issue a QR code for table “'.$table->name.'”?' }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-icon"
                                            title="{{ $table->activeQr ? 'Regenerate — invalidates the printed sticker' : 'Issue a code' }}"
                                            aria-label="{{ $table->activeQr ? 'Regenerate the code for table '.$table->name : 'Issue a code for table '.$table->name }}">
                                        <x-icon name="{{ $table->activeQr ? 'zap' : 'plus' }}" :size="15" />
                                    </button>
                                </form>
                            @endallows

                            @if ($table->activeQr)
                                @allows('dining.qr.delete')
                                    <form method="POST" action="{{ route('admin.qr.revoke', $table) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Withdraw the QR code for table “{{ $table->name }}”? That table can then take no QR orders until a new code is issued.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                title="Withdraw this code"
                                                aria-label="Withdraw the code for table {{ $table->name }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="scan" :size="28" />
                            <h3>No tables to code</h3>
                            <p class="text-sm">
                                Add tables first — each one gets its QR code the moment it is created.
                            </p>
                            @allows('dining.tables.create')
                                <a class="btn btn-primary btn-sm" href="{{ route('admin.tables.index') }}">
                                    Go to Tables
                                </a>
                            @endallows
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
