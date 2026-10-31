@extends('admin.layouts.app')

@section('title', $customer ? $customer->name.' · Ledger' : 'Customer Ledger')

@section('content')
    <x-page-header
        :title="$customer ? $customer->name : 'Customer Ledger'"
        :subtitle="$customer
            ? $customer->reference().' · balance ₹'.number_format((float) $customer->balance, 2)
            : 'Pick a customer to see their account.'"
        :crumbs="['Customers' => null, 'Customer Ledger' => null]"
    >
        <x-slot:actions>
            @if ($customer)
                @allows('crm.ledger.print')
                    <a class="btn btn-sm" target="_blank" rel="noopener"
                       href="{{ route('admin.ledger.statement', array_filter([
                           'customer' => $customer->id,
                           'from' => $from ?: null,
                           'to' => $to ?: null,
                       ])) }}">
                        <x-icon name="file" :size="15" /> Statement
                    </a>
                @endallows

                @allows('finance.payments.create')
                    <a class="btn btn-primary btn-sm"
                       href="{{ route('admin.payments.create', ['customer' => $customer->id]) }}"
                       data-modal="{{ route('admin.payments.create', ['customer' => $customer->id]) }}"
                       data-modal-title="Record a Payment"
                       data-modal-sub="{{ $customer->name }}"
                       data-modal-size="lg">
                        <x-icon name="wallet" :size="15" /> Record payment
                    </a>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- The account is one customer's, so the first question is whose. --}}
    <div class="card" style="margin-bottom:16px">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.ledger.index') }}" class="settings-grid">
                <div class="field pos-customer" data-customer-picker
                     data-lookup-url="{{ route('admin.customers.lookup') }}">
                    <label for="ledger-customer">Customer</label>
                    <input id="ledger-customer" type="search" class="form-control"
                           placeholder="Search by name or mobile…" autocomplete="off"
                           data-customer-search>
                    <div class="line-results" data-customer-results hidden></div>
                    <input type="hidden" name="customer" data-customer-id
                           value="{{ $customer?->id }}">
                </div>

                <div class="field">
                    <label for="ledger-from">From</label>
                    <input id="ledger-from" type="date" name="from" class="form-control" value="{{ $from }}">
                </div>

                <div class="field">
                    <label for="ledger-to">To</label>
                    <input id="ledger-to" type="date" name="to" class="form-control" value="{{ $to }}">
                </div>

                <div class="field">
                    <label for="ledger-type">Entry type</label>
                    <select id="ledger-type" name="type" class="form-control">
                        <option value="">Everything</option>
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="align-self:end">
                    <button type="submit" class="btn btn-primary">Show account</button>
                </div>
            </form>
        </div>
    </div>

    @if (! $customer)
        <div class="card">
            <div class="empty" style="padding:44px 20px">
                <x-icon name="users" :size="30" />
                <h3>Choose a customer</h3>
                <p class="text-sm">
                    Their invoices, payments, returns and write-offs appear here in order, with the
                    balance after each one.
                </p>
            </div>
        </div>
    @else
        @if ($invoices->isNotEmpty())
            <div class="card" style="margin-bottom:16px">
                <div class="card-header">
                    <div>
                        <div class="card-title">Unpaid invoices</div>
                        <div class="text-xs text-muted">
                            {{ $invoices->count() }} outstanding ·
                            ₹{{ number_format((float) $invoices->sum('due_total'), 2) }}
                        </div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Due</th>
                                <th style="text-align:right">Total</th>
                                <th style="text-align:right">Paid</th>
                                <th style="text-align:right">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $bill)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.invoices.show', $bill) }}" class="list-ref">
                                            {{ $bill->number }}
                                        </a>
                                    </td>
                                    <td class="text-sm">{{ $bill->invoiced_at?->format('d M Y') }}</td>
                                    <td class="text-sm">
                                        @if ($bill->due_date)
                                            {{ $bill->due_date->format('d M Y') }}
                                            @if ($bill->isOverdue())
                                                <span class="badge badge-danger" style="margin-left:5px">
                                                    {{ $bill->daysOverdue() }}d over
                                                </span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td style="text-align:right" class="text-sm">
                                        ₹{{ number_format((float) $bill->grand_total, 2) }}
                                    </td>
                                    <td style="text-align:right" class="text-sm">
                                        ₹{{ number_format((float) $bill->paid_total, 2) }}
                                    </td>
                                    <td style="text-align:right">
                                        <strong style="color:var(--danger)">
                                            ₹{{ number_format((float) $bill->due_total, 2) }}
                                        </strong>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Account</div>
                    <div class="text-xs text-muted">
                        Newest entries are at the bottom, as a statement reads.
                    </div>
                </div>
            </div>

            @include('admin.ledger._entries')
        </div>
    @endif
@endsection
