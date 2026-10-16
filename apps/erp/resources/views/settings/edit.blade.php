@extends('layouts.app')

@section('title', 'Settings')

@section('page')
    <x-khana::page-header title="Settings" :subtitle="'How stock behaves at '.$outletName" />

    <form method="POST" action="{{ route('settings.update') }}" class="card" style="max-width:760px">
        @csrf @method('PUT')

        <div class="card-body">
            <div class="field">
                <label class="form-label" for="consume_stage">A dish uses its ingredients</label>
                <select class="form-control" id="consume_stage" name="consume_stage">
                    @foreach ($stages as $key => $label)
                        <option value="{{ $key }}" @selected(old('consume_stage', $settings->consume_stage) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="form-hint">With Restro One CRM. Something that never goes through the kitchen - a cola - is used when it is billed.</div>
            </div>

            <div class="form-section">
                <input type="hidden" name="kitchen_only" value="0">
                <label class="check">
                    <input type="checkbox" name="kitchen_only" value="1" @checked(old('kitchen_only', $settings->kitchen_only))>
                    Cook only with what has been issued to the kitchen
                </label>
                <div class="form-hint">
                    Off: a dish is cooked from wherever the stock is - the kitchen first, then the main store. Right for most kitchens.
                    On: only the kitchen store counts, and it goes negative when nobody issued to it.
                </div>
            </div>

            <div class="form-section">
                <input type="hidden" name="exclude_expired" value="0">
                <label class="check">
                    <input type="checkbox" name="exclude_expired" value="1" @checked(old('exclude_expired', $settings->exclude_expired))>
                    Do not cook with batches past their date
                </label>
                <div class="form-hint">An expired batch stays on the shelf until it is written off, but does not count towards "can we still make this".</div>
            </div>

            <div class="form-section">
                <input type="hidden" name="input_tax_is_cost" value="0">
                <label class="check">
                    <input type="checkbox" name="input_tax_is_cost" value="1" @checked(old('input_tax_is_cost', $settings->input_tax_is_cost))>
                    GST paid on purchases is part of what the stock cost
                </label>
                <div class="form-hint">A restaurant charging 5% GST cannot claim input tax, so it is a cost. Turn off only if this outlet claims it back.</div>
            </div>
        </div>

        <div class="card-footer" style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
@endsection
