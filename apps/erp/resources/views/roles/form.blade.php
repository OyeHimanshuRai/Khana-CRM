@extends('layouts.app')

@php
    use App\Support\Roles;

    $editing = $role !== null;
    $ticked = old('abilities', $ticked);
@endphp

@section('title', $editing ? $role->name : 'New role')

@section('page')
    <x-khana::page-header :title="$editing ? $role->name : 'New role'"
        :subtitle="$editing ? 'What this role may do' : 'Start from the nearest built-in role and change what should differ'"
        :crumbs="['Roles' => route('roles.index'), ($editing ? $role->name : 'New') => null]" />

    {{-- Picking another preset to start from redraws the form with its ticks. --}}
    @unless ($editing)
        <form method="GET" class="card" style="margin-bottom:18px;max-width:820px">
            <div class="list-toolbar">
                <div class="list-filters">
                    <label for="from" class="text-sm text-muted">Start from</label>
                    <select id="from" name="from" onchange="this.form.submit()">
                        @foreach ($presets as $key => $preset)
                            <option value="{{ $key }}" @selected($from === $key)>{{ $preset['name'] }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn btn-sm">Use</button></noscript>
                </div>
            </div>
        </form>
    @endunless

    <form method="POST" action="{{ $editing ? route('roles.update', $role->id) : route('roles.store') }}"
          class="card" style="max-width:820px" data-role-form>
        @csrf
        @if ($editing) @method('PUT') @else <input type="hidden" name="based_on" value="{{ $from }}"> @endif

        <div class="card-body">
            <div class="field">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $name) }}" maxlength="60" required
                       placeholder="Head Chef" @unless ($editing) autofocus @endunless>
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="form-hint">
                Tick what this role should do. A job brings the screens it needs with it: somebody who counts stock can see stock.
            </div>

            @error('abilities')
                <div class="alert alert-danger" role="alert" style="margin-top:12px">{{ $message }}</div>
            @enderror

            @foreach (Roles::GROUPS as $group => $abilities)
                <div class="form-section-title">{{ $group }}</div>

                @foreach ($abilities as $ability)
                    <div class="field" style="margin-bottom:8px">
                        <label class="check">
                            <input type="checkbox" name="abilities[]" value="{{ $ability }}"
                                   data-needs="{{ implode(' ', Roles::NEEDS[$ability] ?? []) }}"
                                   @checked(in_array($ability, $ticked, true))>
                            {{ Roles::ABILITIES[$ability] }}
                        </label>
                    </div>
                @endforeach
            @endforeach
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:space-between;align-items:center">
            <span class="form-hint">Making and changing roles stays with the owner.</span>
            <span style="display:flex;gap:8px">
                <a href="{{ route('roles.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Make role' }}</button>
            </span>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        /*
         | Ticking a job ticks the screens it needs (App\Support\Roles::NEEDS),
         | and they stay ticked for as long as the job is. The server adds
         | them on saving anyway; this only shows it happening.
         */
        (function () {
            var form = document.querySelector('[data-role-form]');
            if (!form) { return; }

            var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="abilities[]"]'));

            function sync() {
                boxes.forEach(function (box) {
                    if (!box.checked) { return; }

                    (box.dataset.needs || '').split(' ').forEach(function (need) {
                        boxes.forEach(function (other) {
                            if (other.value === need) { other.checked = true; }
                        });
                    });
                });
            }

            form.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
