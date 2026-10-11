@extends('khana::layouts.app')

@php $editing = $outlet->exists; @endphp

@section('title', $editing ? 'Edit outlet' : 'Add outlet')

@section('content')
    <x-khana::page-header :title="$editing ? 'Edit outlet' : 'Add outlet'"
        :crumbs="['Outlets' => route('outlets.index'), ($editing ? $outlet->name : 'New') => null]" />

    <form method="POST" action="{{ $editing ? route('outlets.update', $outlet) : route('outlets.store') }}"
          class="card" style="max-width:820px">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card-body">
            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="name">Outlet name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $outlet->name) }}" required>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="code">Short code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $outlet->code) }}" maxlength="12" required>
                    @error('code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label class="form-label" for="address_line1">Address</label>
                <input class="form-control" id="address_line1" name="address_line1" value="{{ old('address_line1', $outlet->address_line1) }}" required>
                @error('address_line1')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <input class="form-control" name="address_line2" value="{{ old('address_line2', $outlet->address_line2) }}" placeholder="Line 2 (optional)">
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="city">City</label>
                    <input class="form-control" id="city" name="city" value="{{ old('city', $outlet->city) }}" required>
                    @error('city')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="pincode">PIN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="pincode" name="pincode" value="{{ old('pincode', $outlet->pincode) }}" maxlength="6">
                    @error('pincode')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="state_code">State</label>
                    <select class="form-control" id="state_code" name="state_code" required>
                        <option value="">Choose…</option>
                        @foreach ($states as $code => $name)
                            <option value="{{ $code }}" @selected(old('state_code', $outlet->state_code) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('state_code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label class="form-label" for="gstin">GSTIN <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="gstin" name="gstin" value="{{ old('gstin', $outlet->gstin) }}" maxlength="15">
                    @error('gstin')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="fssai">FSSAI <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="fssai" name="fssai" value="{{ old('fssai', $outlet->fssai) }}" maxlength="14">
                    @error('fssai')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="form-label" for="phone">Phone <span class="text-muted">(optional)</span></label>
                    <input class="form-control" id="phone" name="phone" value="{{ old('phone', $outlet->phone) }}">
                    @error('phone')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            @unless ($editing)
                <div class="form-section-title">Plan for this outlet</div>
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="plan">Plan</label>
                        <select class="form-control" id="plan" name="plan" required>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->code }}" @selected(old('plan') === $plan->code)>
                                    {{ $plan->name }} — {{ implode(' + ', $plan->productLabels()) }} ({{ $plan->trial_days }}-day trial)
                                </option>
                            @endforeach
                        </select>
                        @error('plan')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="period">Billing</label>
                        <select class="form-control" id="period" name="period" required>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                </div>
            @endunless
        </div>

        <div class="card-footer" style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add outlet' }}</button>
            <a href="{{ route('outlets.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    {{--
        Keys for other billing software. Only where the outlet has ERP -
        there is nowhere to send sales to without it - so $salesKeys is null
        and the whole section is left off everywhere else.
    --}}
    @if ($editing && ($salesKeys ?? null) !== null)
        @php $newKey = session('new_sales_key'); @endphp

        <div class="card" style="max-width:820px;margin-top:16px" id="sales-keys">
            <div class="card-header">
                <div class="card-title">Other billing software</div>
            </div>

            <div class="card-body">
                <p class="text-sm text-muted" style="margin-top:0">
                    If {{ $outlet->name }} bills on software other than Khana CRM, that software can send each sale
                    to Khana ERP, so stock and food cost stay right. Give its vendor a key. A key works for this
                    outlet only.
                </p>

                @if ($newKey)
                    <div class="alert alert-info" style="margin-bottom:16px">
                        <div class="alert-body">
                            <div class="alert-title">Key for {{ $newKey['name'] }}: copy it now</div>
                            <div class="text-sm">The secret is shown this once. If it is lost, revoke the key and make another.</div>
                            <dl class="text-sm" style="margin:10px 0 0;display:grid;grid-template-columns:max-content 1fr;gap:4px 12px">
                                <dt>Client ID</dt><dd style="margin:0"><code style="word-break:break-all">{{ $newKey['id'] }}</code></dd>
                                <dt>Secret</dt><dd style="margin:0"><code style="word-break:break-all">{{ $newKey['secret'] }}</code></dd>
                                <dt>Outlet ID</dt><dd style="margin:0"><code>{{ $outlet->ulid }}</code></dd>
                                <dt>Get a token</dt><dd style="margin:0"><code style="word-break:break-all">POST {{ url('/oauth/token') }}</code> with <code>grant_type=client_credentials</code> and <code>scope=sales:write</code></dd>
                                @if (config('khana.urls.erp'))
                                    <dt>Send a sale</dt><dd style="margin:0"><code style="word-break:break-all">POST {{ rtrim(config('khana.urls.erp'), '/') }}/api/v1/sales</code></dd>
                                @endif
                            </dl>
                        </div>
                    </div>
                @endif

                @if ($salesKeys->isNotEmpty())
                    <div class="table-wrap" style="margin-bottom:16px">
                        <table class="table">
                            <thead>
                                <tr><th>Software</th><th>Client ID</th><th>Made</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($salesKeys as $key)
                                    <tr>
                                        <td><strong>{{ $key->name }}</strong></td>
                                        <td><code>{{ $key->getKey() }}</code></td>
                                        <td>{{ $key->created_at?->format('d M Y') }}</td>
                                        <td class="col-action">
                                            <form method="POST" action="{{ route('outlets.keys.destroy', [$outlet, $key->getKey()]) }}" style="display:inline"
                                                  onsubmit="return confirm('Revoke the key for {{ $key->name }}? That software will stop sending sales.')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-ghost is-danger">Revoke</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <form method="POST" action="{{ route('outlets.keys.store', $outlet) }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    <div class="field" style="margin:0;flex:1;min-width:220px">
                        <label class="form-label" for="software">Name of the software</label>
                        <input class="form-control" id="software" name="software" value="{{ old('software') }}" maxlength="60" placeholder="Petpooja" required>
                        @error('software')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn">Make a key</button>
                </form>
            </div>
        </div>
    @endif
@endsection
