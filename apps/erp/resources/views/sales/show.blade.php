@extends('layouts.app')

@section('title', $sale->invoice_no)

@php
    use App\Services\Availability;
    use App\Support\Units;

    // What the food on each line cost: what the bill itself took out of
    // stock, plus what the kitchen cooked for it.
    $costOf = fn ($line) => $line->cost_paise + (int) ($kitchen[$line->id] ?? collect())->sum('cost_paise');
    $cost = (int) $sale->lines->sum($costOf);
@endphp

@section('page')
    <x-khana::page-header :title="$sale->invoice_no"
        :subtitle="$sale->business_date->format('d M Y').' · '.(App\Models\SalesItem::SOURCES[$sale->source] ?? $sale->source).' · '.str_replace('_', ' ', $sale->channel)"
        :crumbs="['Sales' => route('sales.index'), $sale->invoice_no => null]" />

    @if ($sale->isCancelled())
        <div class="alert alert-danger" role="status" style="margin-bottom:18px">
            Cancelled{{ $sale->cancel_reason ? ': '.$sale->cancel_reason : '' }}. What this bill itself took out of stock was put back.
        </div>
    @endif

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat"><div class="stat-body"><div class="stat-label">Sold for</div><div class="stat-value">&#8377;{{ Units::rupees($sale->net_paise) }}</div><div class="text-xs text-muted">+ &#8377;{{ Units::rupees($sale->tax_paise) }} tax</div></div></div>
        <div class="stat"><div class="stat-body"><div class="stat-label">The food cost</div><div class="stat-value">&#8377;{{ Units::rupees($cost) }}</div></div></div>
        <div class="stat"><div class="stat-body"><div class="stat-label">Food cost</div><div class="stat-value">{{ ($percent = Availability::percent($cost > 0 ? $cost : null, $sale->net_paise)) !== null ? $percent.'%' : '—' }}</div></div></div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Item</th><th style="text-align:right">Qty</th><th style="text-align:right">Amount</th><th style="text-align:right">Cost</th><th>Stock was used</th></tr></thead>
                <tbody>
                    @foreach ($sale->lines as $line)
                        <tr>
                            <td>
                                {{ $line->name }}
                                @if ($line->returned_qty > 0) <span class="badge badge-warning">{{ Units::plain($line->returned_qty) }} returned</span> @endif
                            </td>
                            <td style="text-align:right">{{ Units::plain($line->qty) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($line->net_paise) }}</td>
                            <td style="text-align:right">{{ $costOf($line) > 0 ? '₹'.Units::rupees($costOf($line)) : '—' }}</td>
                            <td class="text-sm">
                                @if (! empty($line->kitchen_refs))
                                    When the kitchen cooked it
                                @elseif ($line->cost_paise > 0)
                                    When it was billed
                                @elseif ($line->sales_item_id === 0)
                                    <span class="text-muted">Not a menu item</span>
                                @else
                                    <span class="badge badge-warning">No recipe</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
