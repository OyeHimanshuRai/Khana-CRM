@extends('admin.layouts.app')

@section('title', 'Export to Tally')

@section('content')
    <x-page-header
        title="Export to Tally"
        :subtitle="$shop ? $shop->name.' · '.$range->label() : 'Choose a branch to export'"
        :crumbs="['Insights' => null, 'Reports' => route('admin.reports.index'), 'Tally' => null]"
    />

    @if ($shop === null)
        {{-- Each branch has its own GSTIN and its own Tally company, so a
             consolidated file would post one outlet's sales into another's
             books. Said here rather than refused after the click. --}}
        <div class="card">
            <div class="empty" style="padding:44px 20px">
                <x-icon name="file" :size="30" />
                <h3>Pick one branch</h3>
                <p class="text-sm">Each branch is its own company in Tally. Switch into a branch from the top bar, then come back here.</p>
            </div>
        </div>
    @else
        <form method="GET" action="{{ route('admin.reports.tally.download') }}">
            <div class="card" style="margin-bottom:16px">
                <div class="card-body">
                    <div class="card-title">Period</div>

                    <div class="settings-grid">
                        <div class="field">
                            <label for="t-preset">Period</label>
                            <select id="t-preset" name="preset" class="form-control">
                                @foreach ($presets as $value => $label)
                                    <option value="{{ $value }}" @selected($range->preset === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-hint">Choose "Custom range" to use the dates beside it.</div>
                        </div>

                        <div class="field">
                            <label for="t-from">From</label>
                            <input id="t-from" type="date" name="from" class="form-control"
                                   value="{{ $range->from->toDateString() }}">
                        </div>

                        <div class="field">
                            <label for="t-to">To</label>
                            <input id="t-to" type="date" name="to" class="form-control"
                                   value="{{ $range->to->toDateString() }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div class="card-body">
                    <div class="card-title">What to export</div>

                    <div class="settings-grid">
                        @foreach ($types as $key => $label)
                            <div class="field">
                                <label class="check">
                                    <input type="checkbox" name="types[]" value="{{ $key }}" checked>
                                    {{ $label }}
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="field" style="margin-top:12px">
                        <label class="check">
                            <input type="hidden" name="masters" value="0">
                            <input type="checkbox" name="masters" value="1" checked>
                            Include ledger masters
                        </label>
                        <div class="form-hint">
                            Creates any ledger the vouchers need (customers, suppliers, Sales @ 5%, Output CGST...).
                            A ledger that already exists is reported by Tally and left as it is; the rest of the file still imports.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div class="card-body text-sm">
                    <div class="card-title">How to import</div>
                    <ol style="margin:0;padding-left:18px;line-height:1.7">
                        <li>Open the company for <strong>{{ $shop->legal_name ?: $shop->name }}</strong> in Tally.</li>
                        <li>TallyPrime: <em>Import → Masters/Transactions</em>. Tally ERP 9: <em>Gateway → Import Data → Vouchers</em>.</li>
                        <li>Choose the downloaded <code>.xml</code> file.</li>
                    </ol>
                    <p class="text-muted" style="margin:10px 0 0">
                        Export each period once. A bill cancelled after it was exported has to be cancelled in Tally by hand too.
                        Stock is not exported: the app keeps the stock ledger.
                    </p>
                </div>
            </div>

            @if ($canDownload)
                <button type="submit" class="btn btn-primary">
                    <x-icon name="download" :size="15" /> Download Tally file
                </button>
            @else
                <p class="text-sm text-muted">Your role can see this screen but not download the file.</p>
            @endif
        </form>
    @endif
@endsection
