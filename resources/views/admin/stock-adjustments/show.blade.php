@extends('admin.layouts.app')

@section('title', $adjustment->reference)

@section('content')
    @php
        $isApplied = $adjustment->status === App\Models\StockAdjustment::APPROVED;
        $canDecide = auth()->user()->can('inventory.adjustments.approve');
        $awaiting = in_array($adjustment->status, [
            App\Models\StockAdjustment::DRAFT,
            App\Models\StockAdjustment::PENDING,
        ], true);
    @endphp

    <x-page-header
        :title="$adjustment->reference"
        :subtitle="$adjustment->reasonLabel().' · '.$adjustment->warehouse?->name"
        :crumbs="['Inventory' => null, 'Stock Adjustments' => route('admin.stock-adjustments.index'), $adjustment->reference => null]"
    >
        <x-slot:actions>
            @if ($adjustment->isEditable())
                @allows('inventory.adjustments.edit')
                    <a class="btn btn-sm" href="{{ route('admin.stock-adjustments.edit', $adjustment) }}">
                        <x-icon name="edit" :size="15" /> Edit
                    </a>
                @endallows
            @endif

            @if ($awaiting && $canDecide)
                {{--
                    Approving is the irreversible act, so it confirms and says
                    what will happen rather than merely asking "are you sure".
                --}}
                <form method="POST" action="{{ route('admin.stock-adjustments.approve', $adjustment) }}"
                      data-ajax data-redirect-delay="600" style="display:inline"
                      onsubmit="return confirm('Apply {{ $adjustment->reference }}? Stock will be corrected immediately and this cannot be undone — only corrected by another adjustment.')">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="user-check" :size="15" /> Approve &amp; apply
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.stock-adjustments.reject', $adjustment) }}"
                      data-ajax data-redirect-delay="600" style="display:inline"
                      onsubmit="return confirm('Reject {{ $adjustment->reference }}? No stock changes.')">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm is-danger">
                        <x-icon name="x" :size="15" /> Reject
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">
                    <span class="badge {{ $adjustment->statusTone() ? 'badge-'.$adjustment->statusTone() : '' }}">
                        <span class="badge-dot"></span> {{ $adjustment->statusLabel() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Shop</dt><dd>{{ $adjustment->shop?->name ?? '—' }}</dd></div>
                <div><dt>Warehouse</dt><dd>{{ $adjustment->warehouse?->name ?? '—' }}</dd></div>
                <div><dt>Count date</dt><dd>{{ $adjustment->adjustment_date?->format('d M Y') }}</dd></div>
                <div><dt>Reason</dt><dd>{{ $adjustment->reasonLabel() }}</dd></div>
                <div><dt>Raised by</dt><dd>{{ $adjustment->created_by_name ?? '—' }}</dd></div>
                <div>
                    <dt>Raised on</dt>
                    <dd>{{ $adjustment->created_at?->format('d M Y H:i') ?? '—' }}</dd>
                </div>

                @if ($adjustment->approved_at)
                    <div>
                        <dt>{{ $isApplied ? 'Applied by' : 'Decided by' }}</dt>
                        <dd>{{ $adjustment->approved_by_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>{{ $isApplied ? 'Applied on' : 'Decided on' }}</dt>
                        <dd>{{ $adjustment->approved_at->format('d M Y H:i') }}</dd>
                    </div>
                @endif

                @if ($isApplied)
                    <div>
                        <dt>Stock in</dt>
                        <dd style="color:var(--success)">
                            +{{ rtrim(rtrim(number_format((float) $adjustment->total_in, 3), '0'), '.') }}
                        </dd>
                    </div>
                    <div>
                        <dt>Stock out</dt>
                        <dd style="color:var(--danger)">
                            −{{ rtrim(rtrim(number_format((float) $adjustment->total_out, 3), '0'), '.') }}
                        </dd>
                    </div>
                    <div>
                        <dt>Value change</dt>
                        <dd>
                            <strong style="color:var({{ (float) $adjustment->value_change < 0 ? '--danger' : '--success' }})">
                                ₹{{ number_format((float) $adjustment->value_change, 2) }}
                            </strong>
                        </dd>
                    </div>
                @endif
            </dl>

            @if ($adjustment->reason)
                <div style="margin-top:16px">
                    <div class="form-label">Notes</div>
                    <p class="text-sm text-muted">{{ $adjustment->reason }}</p>
                </div>
            @endif

            @if ($adjustment->review_note)
                <div style="margin-top:14px">
                    <div class="form-label">Reviewer's note</div>
                    <p class="text-sm text-muted">{{ $adjustment->review_note }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Counted lines</div>
                <div class="text-xs text-muted">{{ $items->count() }} line(s)</div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Batch</th>
                        <th style="text-align:right">Expected</th>
                        <th style="text-align:right">Counted</th>
                        <th style="text-align:right">Difference</th>
                        <th style="text-align:right">Unit cost</th>
                        <th style="text-align:right">Value</th>
                        <th>Note</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($items as $item)
                        @php
                            $difference = (float) $item->difference;
                            $value = $item->valueChange();
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $item->product?->name ?? '—' }}</strong>
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $item->product?->sku }}
                                </span>
                            </td>

                            <td class="text-sm">{{ $item->batch?->batch_no ?? '—' }}</td>

                            <td style="text-align:right" class="text-sm">
                                {{ number_format((float) $item->system_quantity, 3) }}
                            </td>

                            <td style="text-align:right" class="text-sm">
                                {{ number_format((float) $item->counted_quantity, 3) }}
                            </td>

                            <td style="text-align:right;white-space:nowrap">
                                @if (abs($difference) < 0.0005)
                                    <span class="text-muted">no change</span>
                                @else
                                    <strong style="color:var({{ $difference < 0 ? '--danger' : '--success' }})">
                                        {{ $difference > 0 ? '+' : '−' }}{{ number_format(abs($difference), 3) }}
                                    </strong>
                                @endif
                            </td>

                            <td style="text-align:right" class="text-sm">
                                ₹{{ number_format((float) $item->unit_cost, 2) }}
                            </td>

                            <td style="text-align:right;white-space:nowrap">
                                @if (abs($value) < 0.005)
                                    <span class="text-muted">—</span>
                                @else
                                    <span style="color:var({{ $value < 0 ? '--danger' : '--success' }})">
                                        ₹{{ number_format($value, 2) }}
                                    </span>
                                @endif
                            </td>

                            <td class="text-sm text-muted">{{ $item->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty">
                                    <x-icon name="inbox" :size="26" />
                                    <h3>No lines</h3>
                                    <p class="text-sm">This adjustment counts nothing.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
