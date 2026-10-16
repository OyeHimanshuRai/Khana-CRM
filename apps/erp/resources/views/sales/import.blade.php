@extends('layouts.app')

@section('title', 'Import sales')

@section('page')
    <x-khana::page-header title="Import sales" subtitle="For an outlet that bills with something other than Restro One CRM" />

    <form method="POST" action="{{ route('sales.import.upload') }}" enctype="multipart/form-data" class="card" style="max-width:760px;margin-bottom:18px">
        @csrf
        <div class="card-body">
            <div class="field">
                <label class="form-label" for="file">Sales file (CSV)</label>
                <input class="form-control" type="file" id="file" name="file" accept=".csv,text/csv" required>
                @error('file')<div class="field-error">{{ $message }}</div>@enderror
                <div class="form-hint">
                    Export "item-wise sales" from your billing software as CSV. It needs a date, the item name and the quantity
                    sold on each row; amount, tax, size and bill number are used if they are there.
                </div>
            </div>
        </div>
        <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
            <span class="form-hint">Next: check the columns and what each item is. Nothing is saved until you confirm.</span>
            <button type="submit" class="btn btn-primary">Continue</button>
        </div>
    </form>

    <div class="card" style="max-width:760px">
        <div class="card-header"><h2 class="card-title">Earlier imports</h2></div>
        @if ($imports->isEmpty())
            <x-khana::empty icon="inbox" title="Nothing imported yet" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>File</th><th>When</th><th>Rows</th><th>Sales made</th><th>Already here</th></tr></thead>
                    <tbody>
                        @foreach ($imports as $import)
                            <tr>
                                <td>{{ $import->file_name }}</td>
                                <td class="text-sm">{{ App\Support\Outlets::local($import->created_at, $import->outlet_id)->format('d M, H:i') }}<div class="text-xs text-muted">{{ $import->user?->name }}</div></td>
                                <td>{{ $import->rows }}</td>
                                <td>{{ $import->sales }}</td>
                                <td>{{ $import->skipped }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
