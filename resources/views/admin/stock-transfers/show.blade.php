@extends('admin.layouts.app')

@section('title', $transfer->reference)

@section('content')
    @php
        use App\Models\StockTransfer;

        $canApprove = auth()->user()->can('inventory.transfers.approve');
        $canEdit = auth()->user()->can('inventory.transfers.edit');
        $awaiting = in_array($transfer->status, [StockTransfer::DRAFT, StockTransfer::PENDING], true);
    @endphp

    <x-page-header
        :title="$transfer->reference"
        :subtitle="($transfer->fromWarehouse?->name ?? '—').' → '.($transfer->toWarehouse?->name ?? '—')"
        :crumbs="['Inventory' => null, 'Stock Transfers' => route('admin.stock-transfers.index'), $transfer->reference => null]"
    >
        <x-slot:actions>
            @if ($transfer->isEditable() && $canEdit)
                <a class="btn btn-sm" href="{{ route('admin.stock-transfers.edit', $transfer) }}">
                    <x-icon name="edit" :size="15" /> Edit
                </a>
            @endif

            @if ($awaiting && $canApprove)
                <form method="POST" action="{{ route('admin.stock-transfers.approve', $transfer) }}"
                      data-ajax data-redirect-delay="600" style="display:inline">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="user-check" :size="15" /> Approve
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.stock-transfers.reject', $transfer) }}"
                      data-ajax data-redirect-delay="600" style="display:inline"
                      onsubmit="return confirm('Reject {{ $transfer->reference }}? No stock moves.')">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm is-danger">
                        <x-icon name="x" :size="15" /> Reject
                    </button>
                </form>
            @endif

            @if ($transfer->status === StockTransfer::APPROVED && $canEdit)
                {{-- Dispatch is the moment the stock physically leaves, so the
                     confirmation says exactly that. --}}
                <form method="POST" action="{{ route('admin.stock-transfers.dispatch', $transfer) }}"
                      data-ajax data-redirect-delay="600" style="display:inline"
                      onsubmit="return confirm('Dispatch {{ $transfer->reference }}? The stock leaves {{ $transfer->fromWarehouse?->name }} now and will be in transit until it is received.')">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="truck" :size="15" /> Dispatch
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="badge {{ $transfer->statusTone() ? 'badge-'.$transfer->statusTone() : '' }}">
                    <span class="badge-dot"></span> {{ $transfer->statusLabel() }}
                </span>

                @if ($transfer->isInterShop())
                    <span class="badge badge-info" style="margin-left:6px">Inter-shop</span>
                @endif
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>From</dt><dd>{{ $transfer->shop?->name }} · {{ $transfer->fromWarehouse?->name }}</dd></div>
                <div><dt>To</dt><dd>{{ $transfer->toShop?->name }} · {{ $transfer->toWarehouse?->name }}</dd></div>
                <div><dt>Date</dt><dd>{{ $transfer->transfer_date?->format('d M Y') }}</dd></div>
                <div><dt>Raised by</dt><dd>{{ $transfer->created_by_name ?? '—' }}</dd></div>

                @if ($transfer->approved_at)
                    <div><dt>Approved by</dt><dd>{{ $transfer->approved_by_name ?? '—' }}</dd></div>
                    <div><dt>Approved on</dt><dd>{{ $transfer->approved_at->format('d M Y H:i') }}</dd></div>
                @endif

                @if ($transfer->dispatched_at)
                    <div><dt>Dispatched</dt><dd>{{ $transfer->dispatched_at->format('d M Y H:i') }}</dd></div>
                @endif

                @if ($transfer->received_at)
                    <div><dt>Received by</dt><dd>{{ $transfer->received_by_name ?? '—' }}</dd></div>
                    <div><dt>Received on</dt><dd>{{ $transfer->received_at->format('d M Y H:i') }}</dd></div>
                @endif

                <div>
                    <dt>Quantity</dt>
                    <dd>{{ rtrim(rtrim(number_format((float) $transfer->total_quantity, 3), '0'), '.') }}</dd>
                </div>

                @if ((float) $transfer->total_value > 0)
                    <div><dt>Value</dt><dd>₹{{ number_format((float) $transfer->total_value, 2) }}</dd></div>
                @endif
            </dl>

            @if ($transfer->note)
                <div style="margin-top:16px">
                    <div class="form-label">Note</div>
                    <p class="text-sm text-muted">{{ $transfer->note }}</p>
                </div>
            @endif

            @if ($transfer->review_note)
                <div style="margin-top:14px">
                    <div class="form-label">Reviewer's note</div>
                    <p class="text-sm text-muted">{{ $transfer->review_note }}</p>
                </div>
            @endif
        </div>
    </div>

    {{--
        Receiving is a form, not a button: the receiver counts what actually
        arrived, and a short delivery is a real event rather than a mistake
        to be papered over. Quantities default to what was sent.
    --}}
    @if ($canReceive)
        <form method="POST" action="{{ route('admin.stock-transfers.receive', $transfer) }}"
              data-ajax data-redirect-delay="600">
            @csrf
            @method('PUT')

            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div>
                        <div class="card-title">Book in the consignment</div>
                        <div class="text-xs text-muted">
                            Enter what actually arrived. Anything less than what was sent is recorded
                            as a shortfall and flagged in the activity log.
                        </div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Batch</th>
                                <th style="text-align:right">Sent</th>
                                <th style="width:150px;text-align:right">Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item->product?->name }}</strong>
                                        <span class="text-xs text-muted" style="display:block">
                                            {{ $item->product?->sku }}
                                        </span>
                                    </td>
                                    <td class="text-sm">{{ $item->batch?->batch_no ?? '—' }}</td>
                                    <td style="text-align:right" class="text-sm">
                                        {{ number_format((float) $item->quantity, 3) }}
                                    </td>
                                    <td>
                                        <label class="sr-only" for="recv-{{ $item->id }}">
                                            Quantity received for {{ $item->product?->name }}
                                        </label>
                                        <input id="recv-{{ $item->id }}" type="number"
                                               name="received[{{ $item->id }}]" class="form-control"
                                               value="{{ (float) $item->quantity }}"
                                               min="0" max="{{ (float) $item->quantity }}"
                                               step="{{ $item->product?->unit?->allow_decimal ? '0.001' : '1' }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-body">
                    <div class="field">
                        <label for="recv-note">Note</label>
                        <textarea id="recv-note" name="review_note" class="form-control"
                                  style="min-height:56px" aria-invalid="false"
                                  placeholder="Condition on arrival, seal numbers, anything short."></textarea>
                    </div>
                </div>

                <div class="card-footer" style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="inbox" :size="15" /> Receive into {{ $transfer->toWarehouse?->name }}
                    </button>
                </div>
            </div>
        </form>
    @else
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Lines</div>
                    <div class="text-xs text-muted">{{ $items->count() }} line(s)</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Batch</th>
                            <th style="text-align:right">Sent</th>
                            <th style="text-align:right">Received</th>
                            <th style="text-align:right">Short</th>
                            <th style="text-align:right">Unit cost</th>
                            <th>Note</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($items as $item)
                            @php $short = $item->shortfall(); @endphp
                            <tr>
                                <td>
                                    <strong>{{ $item->product?->name ?? '—' }}</strong>
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $item->product?->sku }}
                                    </span>
                                </td>

                                <td class="text-sm">{{ $item->batch?->batch_no ?? '—' }}</td>

                                <td style="text-align:right" class="text-sm">
                                    {{ number_format((float) $item->quantity, 3) }}
                                </td>

                                <td style="text-align:right" class="text-sm">
                                    {{ $item->received_quantity === null
                                        ? '—'
                                        : number_format((float) $item->received_quantity, 3) }}
                                </td>

                                <td style="text-align:right">
                                    @if ($short === null)
                                        <span class="text-muted">—</span>
                                    @elseif ($short > 0.0005)
                                        <strong style="color:var(--danger)">{{ number_format($short, 3) }}</strong>
                                    @else
                                        <span style="color:var(--success)">in full</span>
                                    @endif
                                </td>

                                <td style="text-align:right" class="text-sm">
                                    ₹{{ number_format((float) $item->unit_cost, 2) }}
                                </td>

                                <td class="text-sm text-muted">{{ $item->note ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty">
                                        <x-icon name="inbox" :size="26" />
                                        <h3>No lines</h3>
                                        <p class="text-sm">This transfer moves nothing.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
