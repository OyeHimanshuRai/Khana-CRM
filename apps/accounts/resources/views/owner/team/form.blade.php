@extends('khana::layouts.app')

@php $editing = $member !== null; @endphp

@section('title', $editing ? 'Edit access' : 'Invite someone')

@section('content')
    <x-khana::page-header :title="$editing ? 'Edit '.$member->name : 'Invite someone'"
        :crumbs="['Team' => route('team.index'), ($editing ? $member->name : 'Invite') => null]" />

    <form method="POST" action="{{ $editing ? route('team.update', $member) : route('team.store') }}"
          class="card" style="max-width:820px">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card-body">
            @unless ($editing)
                <div class="field">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}">
                        @error('email')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="mobile">Mobile</label>
                        <input class="form-control" id="mobile" name="mobile" value="{{ old('mobile') }}">
                        @error('mobile')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-hint">An email or a mobile - either is enough to sign in.</div>
            @else
                <p class="text-sm text-muted">{{ $member->email ?? $member->mobile }}</p>
            @endunless

            @foreach (['crm' => 'CRM', 'erp' => 'ERP'] as $product => $label)
                @php
                    $held = $access[$product] ?? null;
                    $heldRole = old("access.$product.role_key", $held?->role_key);
                    $heldAll = old("access.$product.all_outlets", $held?->all_outlets);
                    $heldOutlets = old("access.$product.outlet_ids", $held ? $held->outlets->pluck('id')->all() : []);
                @endphp

                <div class="form-section-title">{{ $label }}</div>
                <div class="field">
                    <label class="form-label" for="{{ $product }}_role">Role</label>
                    <select class="form-control" id="{{ $product }}_role" name="access[{{ $product }}][role_key]">
                        <option value="">No access</option>
                        @foreach ($presets[$product] as $key => $preset)
                            <option value="{{ $key }}" @selected($heldRole === $key)>{{ $preset['name'] }} — {{ $preset['can'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="access[{{ $product }}][all_outlets]" value="1" @checked($heldAll)>
                        All outlets (including ones opened later)
                    </label>
                </div>
                <div class="field">
                    <div class="form-label">Or pick outlets</div>
                    <div style="display:flex;flex-wrap:wrap;gap:12px">
                        @foreach ($outlets as $outlet)
                            <label class="check">
                                <input type="checkbox" name="access[{{ $product }}][outlet_ids][]" value="{{ $outlet->id }}"
                                       @checked(in_array($outlet->id, (array) $heldOutlets))>
                                {{ $outlet->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer" style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Save access' : 'Send invitation' }}</button>
            <a href="{{ route('team.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
