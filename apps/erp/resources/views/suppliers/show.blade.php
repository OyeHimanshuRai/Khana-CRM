@extends('layouts.app')

@section('title', $supplier->name)

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header :title="$supplier->name"
        :subtitle="$supplier->terms_days ? 'Pay within '.$supplier->terms_days.' days' : 'Pay on delivery'"
        :crumbs="['Suppliers' => route('suppliers.index'), $supplier->name => null]">
        <x-slot:actions>
            @can('receive')
                <a href="{{ route('receipts.create') }}" class="btn">Receive goods</a>
            @endcan
        </x-slot:actions>
    </x-khana::page-header>

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">{{ $owed < 0 ? 'Paid in advance' : 'You owe' }}</div>
                <div class="stat-value">&#8377;{{ Units::rupees(abs($owed)) }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Open bills</div>
                <div class="stat-value">{{ $bills->where('status', 'open')->count() }}</div>
            </div>
        </div>
    </div>

    @can('bills.manage')
        <form method="POST" action="{{ route('payments.store') }}" class="card" style="margin-bottom:18px">
            @csrf
            <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
            <div class="card-header"><h2 class="card-title">Record a payment</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="amount">Amount (&#8377;)</label>
                        <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount', $owed > 0 ? Units::plain($owed * 100) : '') }}" required>
                        @error('amount')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="method">Paid by</label>
                        <select class="form-control" id="method" name="method">
                            @foreach ($methods as $key => $label) <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option> @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="paid_on">On</label>
                        <input class="form-control" type="date" id="paid_on" name="paid_on" value="{{ old('paid_on', $today) }}" required>
                    </div>
                    <div class="field">
                        <label class="form-label" for="reference">Reference <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="reference" name="reference" value="{{ old('reference') }}" placeholder="UTR, cheque no.">
                    </div>
                </div>
                <div class="form-hint">Settles the oldest bills first. Anything over what is owed is kept as an advance.</div>
            </div>
            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary">Record payment</button>
            </div>
        </form>
    @endcan

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">Bills</h2></div>
        @if ($bills->isEmpty())
            <x-khana::empty icon="file" title="No bills yet" text="A bill is raised when you receive goods from this supplier." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Bill</th><th>Date</th><th>Due</th><th style="text-align:right">Total</th><th style="text-align:right">Still owed</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($bills as $bill)
                            <tr>
                                <td>{{ $bill->bill_no }}</td>
                                <td>{{ $bill->billed_on->format('d M Y') }}</td>
                                <td>
                                    {{ $bill->due_on?->format('d M Y') ?? '—' }}
                                    @if ($bill->isOverdue($today)) <span class="badge badge-danger">Overdue</span> @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($bill->total_paise) }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($bill->duePaise()) }}</td>
                                <td><span class="badge badge-{{ $bill->status === 'paid' ? 'success' : 'warning' }}">{{ ucfirst($bill->status) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">Payments and credits</h2></div>
        @if ($payments->isEmpty())
            <x-khana::empty icon="wallet" title="Nothing paid yet" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>How</th><th>Against</th><th>Reference</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ $payment->paid_on->format('d M Y') }}</td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td>{{ $payment->bill?->bill_no ?? 'Advance' }}</td>
                                <td class="text-sm text-muted">{{ $payment->reference ?? $payment->notes }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($payment->amount_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @can('purchasing.manage')
        <form method="POST" action="{{ route('suppliers.send-back', $supplier->id) }}" class="card" style="margin-bottom:18px">
            @csrf
            <div class="card-header"><h2 class="card-title">Send goods back</h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="store_id">From</label>
                        <select class="form-control" id="store_id" name="store_id">
                            @foreach ($stores as $store) <option value="{{ $store->id }}">{{ $store->name }}</option> @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="returned_on">On</label>
                        <input class="form-control" type="date" id="returned_on" name="returned_on" value="{{ old('returned_on', $today) }}" required>
                    </div>
                    <div class="field">
                        <label class="form-label" for="reason">Why</label>
                        <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}" placeholder="Sour on arrival, wrong item…" required>
                        @error('reason')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            @include('partials.lines', ['catalogue' => $catalogue, 'blank' => 1])
            <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
                <span class="form-hint">The stock leaves and what it was worth comes off what you owe.</span>
                <button type="submit" class="btn">Send back</button>
            </div>
        </form>

        <form method="POST" action="{{ route('suppliers.update', $supplier->id) }}" class="card" style="max-width:820px">
            @csrf @method('PUT')
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <div class="card-body">
                @include('suppliers.fields', ['supplier' => $supplier])

                <input type="hidden" name="is_active" value="0">
                <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active))> Still buying from them</label>
            </div>
            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    @endcan
@endsection
