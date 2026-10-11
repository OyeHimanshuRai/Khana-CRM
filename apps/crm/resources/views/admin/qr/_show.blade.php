{{--
    One code, big enough to scan straight off the screen.

    That is not a gimmick: it is how a manager checks the code works before
    printing sixty of them, and how a guest at a table whose sticker has gone
    missing gets the menu without waiting for a reprint.
--}}

<div class="cat-view-body" style="text-align:center">
    @if ($code)
        <div style="display:inline-block;padding:14px;background:#fff;border-radius:var(--radius);
                    border:1px solid var(--border-strong)">
            {!! $code !!}
        </div>

        <div class="sec-name" style="margin-top:12px">
            Table {{ $table->name }}
            <span class="list-ref">{{ $table->code }}</span>
        </div>

        <p class="text-sm text-muted" style="margin-top:2px">{{ $table->fullName() }}</p>

        {{--
            The URL in plain text underneath. Somebody will need to type it
            into a test phone, paste it into a print house's artwork, or check
            that the sticker on the table is the one this screen is showing.
        --}}
        <p class="text-xs text-muted" style="margin-top:12px;word-break:break-all">
            {{ $table->activeQr->url() }}
        </p>

        <dl class="sec-facts" style="margin-top:14px;text-align:left">
            <div>
                <dt>Issued</dt>
                <dd>{{ $table->activeQr->issued_at?->format('d M Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt>By</dt>
                <dd>{{ $table->activeQr->issuer?->name ?? 'System' }}</dd>
            </div>
            <div>
                <dt>Scans</dt>
                <dd>{{ number_format($table->activeQr->scan_count) }}</dd>
            </div>
            <div>
                <dt>Last scanned</dt>
                <dd>{{ $table->activeQr->last_scanned_at?->diffForHumans() ?? 'Never' }}</dd>
            </div>
        </dl>
    @else
        <div class="empty">
            <x-icon name="scan" :size="28" />
            <h3>No code on this table</h3>
            <p class="text-sm">
                Table {{ $table->name }} cannot take QR orders until one is issued.
            </p>
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('dining.qr.print')
        <a class="btn" href="{{ route('admin.qr.sheet', ['floor_id' => $table->floor_id, 'q' => $table->code]) }}"
           target="_blank" rel="noopener">
            <x-icon name="file" :size="15" /> Print
        </a>
    @endallows

    @allows('dining.qr.create')
        <form method="POST" action="{{ route('admin.qr.regenerate', $table) }}"
              data-ajax data-close-modal data-refresh-list style="display:inline"
              onsubmit="return confirm('{{ $table->activeQr
                  ? 'Give table “'.$table->name.'” a new QR code? Every sticker already on that table stops working immediately.'
                  : 'Issue a QR code for table “'.$table->name.'”?' }}')">
            @csrf
            <button type="submit" class="btn btn-primary">
                <x-icon name="{{ $table->activeQr ? 'zap' : 'plus' }}" :size="15" />
                {{ $table->activeQr ? 'Regenerate' : 'Issue a code' }}
            </button>
        </form>
    @endallows
</div>
