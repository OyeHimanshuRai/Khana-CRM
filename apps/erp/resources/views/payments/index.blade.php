@extends('layouts.app')

@section('title', 'Supplier payments')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Payments" subtitle="Money paid to suppliers" />

    @can('bills.manage')
        @if ($suppliers->isNotEmpty())
            <form method="POST" action="{{ route('payments.store') }}" class="card" style="margin-bottom:18px">
                @csrf
                <div class="card-header"><h2 class="card-title">Record a payment</h2></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="field">
                            <label class="form-label" for="supplier_id">To</label>
                            <select class="form-control" id="supplier_id" name="supplier_id" required>
                                <option value="">Choose a supplier…</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((int) old('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('supplier_id')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label class="form-label" for="amount">Amount (&#8377;)</label>
                            <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
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
                            <input class="form-control" id="reference" name="reference" value="{{ old('reference') }}">
                        </div>
                    </div>
                    <div class="form-hint">Settles that supplier's oldest bills first. Anything over what is owed is kept as an advance.</div>
                </div>
                <div class="card-footer" style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary">Record payment</button>
                </div>
            </form>
        @endif
    @endcan

    <div class="card">
        @if ($payments->isEmpty())
            <x-khana::empty icon="wallet" title="No payments yet" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Supplier</th><th>How</th><th>Against</th><th>Reference</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ $payment->paid_on->format('d M Y') }}</td>
                                <td><a href="{{ route('suppliers.show', $payment->supplier_id) }}">{{ $payment->supplier?->name }}</a></td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td>{{ $payment->bill?->bill_no ?? 'Advance' }}</td>
                                <td class="text-sm text-muted">{{ $payment->reference ?? $payment->notes }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($payment->amount_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $payments])
        @endif
    </div>
@endsection
