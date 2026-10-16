@extends('layouts.app')

@section('title', 'Paid-outs')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Paid-outs" subtitle="Cash that left the till in Restro One CRM, waiting to be filed" />

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">To file <span class="badge badge-{{ $pending->isEmpty() ? 'success' : 'warning' }}">{{ $pending->count() }}</span></h2></div>

        @if ($pending->isEmpty())
            <x-khana::empty icon="check" title="Nothing to file"
                text="When a cashier takes cash out of the till, it shows here for you to say what it was." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>The cashier said</th><th style="text-align:right">Amount</th><th style="min-width:420px">It was</th></tr></thead>
                    <tbody>
                        @foreach ($pending as $paidOut)
                            <tr>
                                <td>{{ $paidOut->business_date->format('d M Y') }}</td>
                                <td>
                                    {{ ucfirst(str_replace('_', ' ', $paidOut->category)) }}
                                    @if ($paidOut->note) <div class="text-xs text-muted">{{ $paidOut->note }}</div> @endif
                                </td>
                                <td style="text-align:right"><strong>&#8377;{{ Units::rupees($paidOut->amount_paise) }}</strong></td>
                                <td>
                                    @can('bills.manage')
                                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                                            <form method="POST" action="{{ route('paid-outs.file', $paidOut->id) }}" style="display:flex;gap:6px">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="as" value="payment">
                                                <select class="form-control" name="supplier_id" style="width:170px" aria-label="Supplier" required>
                                                    <option value="">A payment to…</option>
                                                    @foreach ($suppliers as $supplier) <option value="{{ $supplier->id }}">{{ $supplier->name }}</option> @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm">File</button>
                                            </form>

                                            <form method="POST" action="{{ route('paid-outs.file', $paidOut->id) }}" style="display:flex;gap:6px">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="as" value="expense">
                                                <select class="form-control" name="category" style="width:170px" aria-label="Expense category" required>
                                                    <option value="">An expense on…</option>
                                                    @foreach ($categories as $key => $label)
                                                        <option value="{{ $key }}" @selected($paidOut->category === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm">File</button>
                                            </form>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($filed->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="card-title">Filed</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Filed as</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($filed as $paidOut)
                            <tr>
                                <td>{{ $paidOut->business_date->format('d M Y') }}</td>
                                <td>
                                    @if ($paidOut->payment)
                                        Payment to {{ $paidOut->payment->supplier?->name }}
                                    @elseif ($paidOut->expense)
                                        Expense: {{ $paidOut->expense->categoryLabel() }}
                                    @endif
                                    @if ($paidOut->note) <div class="text-xs text-muted">{{ $paidOut->note }}</div> @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($paidOut->amount_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
