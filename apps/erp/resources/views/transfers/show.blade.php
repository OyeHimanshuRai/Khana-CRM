@extends('layouts.app')

@section('title', $transfer->number)

@php
    use App\Support\Units;

    $toReceive = $incoming && $transfer->isOnItsWay() && auth()->user()->can('receive');
    $received = $transfer->status === App\Models\StockTransfer::RECEIVED;
@endphp

@section('page')
    <x-khana::page-header :title="$transfer->number"
        :subtitle="'From '.$transfer->fromName().' to '.$transfer->toName().', sent '.$transfer->sent_on->format('d M Y').($transfer->user ? ' by '.$transfer->user->name : '')"
        :crumbs="['Transfers' => route('transfers.index'), $transfer->number => null]">
        <x-slot:actions>
            <button type="button" class="btn" onclick="window.print()">Print</button>

            @if (! $incoming && $transfer->isOnItsWay())
                @can('transfer')
                    <form method="POST" action="{{ route('transfers.cancel', $transfer->id) }}"
                          onsubmit="return confirm('Cancel this transfer and put the stock back on your shelf?')">
                        @csrf @method('PUT')
                        <button type="submit" class="btn btn-ghost">Cancel transfer</button>
                    </form>
                @endcan
            @endif
        </x-slot:actions>
    </x-khana::page-header>

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Status</div>
                <div class="stat-value" style="font-size:16px"><span class="badge badge-{{ $transfer->statusTone() }}">{{ $transfer->statusLabel() }}</span></div>
                <div class="text-xs text-muted">
                    @if ($received)
                        {{ $transfer->received_on->format('d M Y') }}, into {{ $transfer->toStore?->name }}{{ $transfer->receiver ? ' by '.$transfer->receiver->name : '' }}
                    @elseif ($transfer->isOnItsWay())
                        Left {{ $transfer->fromStore?->name }}; not on either shelf until it is received
                    @else
                        The stock went back to {{ $transfer->fromStore?->name }}
                    @endif
                </div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Worth</div>
                <div class="stat-value">&#8377;{{ Units::rupees($transfer->value_paise) }}</div>
                <div class="text-xs text-muted">at what it cost {{ $transfer->fromName() }}</div>
            </div>
        </div>
        @if ($transfer->short_paise > 0)
            <div class="stat">
                <div class="stat-body">
                    <div class="stat-label">Did not arrive</div>
                    <div class="stat-value" style="color:var(--danger)">&#8377;{{ Units::rupees($transfer->short_paise) }}</div>
                    <div class="text-xs text-muted">written off at {{ $transfer->toName() }}</div>
                </div>
            </div>
        @endif
    </div>

    @if ($toReceive)
        <form method="POST" action="{{ route('transfers.receive', $transfer->id) }}" class="card">
            @csrf @method('PUT')

            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="to_store_id">Put it in</label>
                        <select class="form-control" id="to_store_id" name="to_store_id">
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" @selected((int) old('to_store_id', $into->id) === $store->id)>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="on_date">Received on</label>
                        <input class="form-control" type="date" id="on_date" name="on_date" value="{{ old('on_date', $today) }}" required>
                    </div>
                </div>
            </div>
    @else
        <div class="card">
    @endif

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th><th>Batch</th>
                            <th style="text-align:right">Sent</th>
                            @if ($toReceive)
                                <th style="width:150px">Arrived</th><th style="width:60px"></th>
                            @elseif ($received)
                                <th style="text-align:right">Arrived</th><th style="text-align:right">Short</th>
                            @endif
                            <th style="text-align:right">Worth</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfer->lines as $line)
                            @php $big = Units::big($line->item->base_unit); @endphp
                            <tr>
                                <td>{{ $line->item->name }}</td>
                                <td class="text-sm">
                                    @if ($line->batch_no)
                                        {{ $line->batch_no }}
                                        @if ($line->expiry_date) <div class="text-xs text-muted">Expires {{ $line->expiry_date->format('d M Y') }}</div> @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align:right">{{ $line->item->show($line->qty) }}</td>
                                @if ($toReceive)
                                    <td>
                                        <input class="form-control" name="received[{{ $line->id }}]"
                                               value="{{ old('received.'.$line->id, Units::inBig($line->qty, $line->item->base_unit)) }}"
                                               inputmode="decimal" autocomplete="off" aria-label="How much {{ $line->item->name }} arrived">
                                        @error('received.'.$line->id)<div class="field-error">{{ $message }}</div>@enderror
                                    </td>
                                    <td class="text-muted">{{ $big }}</td>
                                @elseif ($received)
                                    <td style="text-align:right">{{ $line->item->show($line->received_qty ?? 0) }}</td>
                                    <td style="text-align:right;{{ $line->shortQty() > 0 ? 'color:var(--danger)' : '' }}">
                                        {{ $line->shortQty() > 0 ? $line->item->show($line->shortQty()) : '—' }}
                                    </td>
                                @endif
                                <td style="text-align:right">&#8377;{{ Units::rupees($line->value_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($transfer->note)
                <div class="card-body text-sm text-muted">{{ $transfer->note }}</div>
            @endif

    @if ($toReceive)
            <div class="card-footer" style="display:flex;gap:8px;justify-content:space-between;align-items:center">
                <span class="form-hint">Each box starts at what was sent. Change one only if less arrived - the rest is written off against this transfer.</span>
                <button type="submit" class="btn btn-primary">Receive</button>
            </div>
        </form>
    @else
        </div>
    @endif
@endsection
