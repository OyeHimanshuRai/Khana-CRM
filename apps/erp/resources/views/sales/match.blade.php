@extends('layouts.app')

@section('title', 'Import sales')

@section('page')
    <x-khana::page-header title="Check the file" :subtitle="$fileName.' · '.$rowCount.' rows'"
        :crumbs="['Sales' => route('sales.index'), 'Import' => route('sales.import'), 'Check' => null]" />

    {{-- 1. Which column is which. Changing one reloads the page with it applied. --}}
    <form method="GET" class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">1. Which column is which</h2></div>
        <div class="card-body">
            <div class="form-row">
                @foreach ($fields as $field => $label)
                    <div class="field">
                        <label class="form-label" for="col-{{ $field }}">
                            {{ $label }}
                            @unless (in_array($field, $required, true)) <span class="text-muted">(optional)</span> @endunless
                        </label>
                        <select class="form-control" id="col-{{ $field }}" name="{{ $field }}" onchange="this.form.submit()">
                            <option value="">{{ in_array($field, $required, true) ? 'Choose…' : 'Not in this file' }}</option>
                            @foreach ($headers as $index => $heading)
                                <option value="{{ $index }}" @selected($layout[$field] === $index)>{{ $heading ?: 'Column '.($index + 1) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <noscript><button type="submit" class="btn btn-sm">Apply</button></noscript>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead><tr>@foreach ($headers as $heading) <th>{{ $heading }}</th> @endforeach</tr></thead>
                <tbody>
                    @foreach ($sample as $row)
                        <tr>@foreach ($headers as $index => $heading) <td class="text-sm">{{ $row[$index] ?? '' }}</td> @endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer text-xs text-muted">The first {{ count($sample) }} rows of the file. ERP remembers this layout for next time.</div>
    </form>

    @if (! $ready)
        <div class="alert alert-warning" role="status">Choose the date, item name and quantity columns above to continue.</div>
    @else
        {{-- 2. What each name stands for. --}}
        <form method="POST" action="{{ route('sales.import.store', $token) }}" class="card">
            @csrf

            @foreach ($layout as $field => $index)
                <input type="hidden" name="layout[{{ $field }}]" value="{{ $index }}">
            @endforeach

            <div class="card-header"><h2 class="card-title">2. What each item is</h2></div>
            <div class="card-body text-sm text-muted" style="padding-bottom:0">
                {{ count($names) }} different items in this file. One ERP has not seen becomes a new dish, which then waits for its recipe.
                Matched once, a name is remembered.
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>In the file</th><th>Rows</th><th style="min-width:280px">Is our</th></tr></thead>
                    <tbody>
                        @foreach ($names as $i => $name)
                            @php $chosen = $name['sales_item_id'] === null ? '' : $name['sales_item_id'].($name['sales_item_size_id'] ? ':'.$name['sales_item_size_id'] : ''); @endphp
                            <tr>
                                <td>
                                    <strong>{{ $name['item'] }}</strong>
                                    @if ($name['size']) <span class="text-muted">· {{ $name['size'] }}</span> @endif
                                    <input type="hidden" name="match[{{ $i }}][alias]" value="{{ $name['alias'] }}">
                                </td>
                                <td>{{ $name['rows'] }}</td>
                                <td>
                                    <select class="form-control" name="match[{{ $i }}][choice]" aria-label="What {{ $name['item'] }} is">
                                        <option value="">A new dish</option>
                                        @foreach ($dishes as $dish)
                                            @if ($dish->sizes->isEmpty())
                                                <option value="{{ $dish->id }}" @selected($chosen === (string) $dish->id)>{{ $dish->name }}</option>
                                            @else
                                                @foreach ($dish->sizes as $size)
                                                    <option value="{{ $dish->id }}:{{ $size->id }}" @selected($chosen === $dish->id.':'.$size->id)>{{ $dish->name }} · {{ $size->name }}</option>
                                                @endforeach
                                            @endif
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer" style="display:flex;gap:8px;justify-content:space-between;align-items:center">
                <span class="form-hint">Importing uses stock for every dish that has a recipe. A sale already imported is left alone.</span>
                <span style="display:flex;gap:8px">
                    <a href="{{ route('sales.import') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Import {{ $rowCount }} rows</button>
                </span>
            </div>
        </form>
    @endif
@endsection
