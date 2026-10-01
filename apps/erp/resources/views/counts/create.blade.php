@extends('layouts.app')

@section('title', 'Count stock')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Count stock" :subtitle="'What is really in '.$store->name"
        :crumbs="['Counts' => route('counts.index'), 'New' => null]" />

    {{-- Which store, and whether to list items the books say it has none of. --}}
    <form method="GET" class="card" style="margin-bottom:18px">
        <div class="list-toolbar">
            <div class="list-filters">
                <label for="store" class="sr-only">Store</label>
                <select id="store" name="store" onchange="this.form.submit()">
                    @foreach ($stores as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $store->id)>{{ $option->name }}</option>
                    @endforeach
                </select>

                <label class="check" style="font-size:13px">
                    <input type="checkbox" name="all" value="1" @checked($everything) onchange="this.form.submit()">
                    List every item, not only what the books say is here
                </label>
            </div>
        </div>
    </form>

    @if ($rows === [])
        <div class="card">
            <x-khana::empty icon="package" title="The books say this store is empty"
                text="Tick &quot;List every item&quot; above to count what is actually here - this is how opening stock is entered.">
                <a href="{{ route('counts.create', ['store' => $store->id, 'all' => 1]) }}" class="btn btn-primary">List every item</a>
            </x-khana::empty>
        </div>
    @else
        <form method="POST" action="{{ route('counts.store') }}" class="card">
            @csrf
            <input type="hidden" name="store_id" value="{{ $store->id }}">

            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="on_date">Counted on</label>
                        <input class="form-control" type="date" id="on_date" name="on_date" value="{{ old('on_date', $today) }}" required>
                    </div>
                    <div class="field">
                        <label class="form-label" for="note">Note <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="note" name="note" value="{{ old('note') }}" maxlength="500">
                    </div>
                </div>

                <input type="hidden" name="opening" value="0">
                <label class="check">
                    <input type="checkbox" name="opening" value="1" @checked(old('opening'))>
                    This is opening stock - it was here before ERP, not found or lost
                </label>

                @error('rows')
                    <div class="alert alert-danger" role="alert" style="margin-top:12px">{{ $message }}</div>
                @enderror
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th><th>Batch</th>
                            <th style="text-align:right">Books say</th>
                            <th style="width:150px">Counted</th>
                            <th style="width:60px"></th>
                            <th style="width:170px">Cost per unit (&#8377;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            @php $item = $row['item']; $big = Units::big($item->base_unit); $quote = Units::quote($item->last_cost_rate, $item->base_unit); @endphp
                            <tr>
                                <td>
                                    {{ $item->name }}
                                    <div class="text-xs text-muted">{{ $item->category }}</div>
                                    <input type="hidden" name="rows[{{ $i }}][item]" value="{{ $item->id }}">
                                    <input type="hidden" name="rows[{{ $i }}][batch_id]" value="{{ $row['batch_id'] }}">
                                </td>
                                <td class="text-sm">
                                    @if ($row['batch'])
                                        {{ $row['batch']->batch_no }}
                                        @if ($row['batch']->expiry_date) <div class="text-xs text-muted">Expires {{ $row['batch']->expiry_date->format('d M') }}</div> @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align:right">{{ $item->show($row['expected']) }}</td>
                                <td>
                                    <input class="form-control" name="rows[{{ $i }}][qty]" value="{{ old("rows.$i.qty") }}"
                                           inputmode="decimal" autocomplete="off" aria-label="Counted {{ $item->name }}">
                                    @error("rows.$i.qty")<div class="field-error">{{ $message }}</div>@enderror
                                </td>
                                <td class="text-muted">{{ $big }}</td>
                                <td>
                                    <input class="form-control" name="rows[{{ $i }}][price]" value="{{ old("rows.$i.price") }}"
                                           inputmode="decimal" autocomplete="off" aria-label="Cost per {{ $big }}"
                                           placeholder="{{ $item->last_cost_rate > 0 ? Units::rupees($quote['paise']).' / '.$quote['unit'] : 'per '.$big }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:space-between;align-items:center">
                <span class="form-hint">Leave a row blank if you did not count it. Cost is only needed for stock the books have never seen.</span>
                <span style="display:flex;gap:8px">
                    <a href="{{ route('counts.index') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Post count</button>
                </span>
            </div>
        </form>
    @endif
@endsection
