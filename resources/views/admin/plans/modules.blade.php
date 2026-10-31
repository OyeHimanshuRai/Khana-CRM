@extends('admin.layouts.app')

@section('title', 'Modules & Plans')

@section('content')
    <x-page-header
        title="Modules & Plans"
        subtitle="Which plan and which client gets each module"
        :crumbs="['Settings' => null, 'Plans' => route('admin.plans.index'), 'Modules' => null]"
    >
        <x-slot:actions>
            <x-info label="How this works">
                A new module joins no plan by itself: tick it here for the plans and clients who should have it.
                Adding one switches it on for the outlets on that plan. Removing one closes its screens from the next
                request, and keeps all their data.
            </x-info>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.plans.modules.update') }}" data-ajax class="card">
        @csrf
        @method('PUT')

        {{-- An empty entry per plan, so a plan with every box unticked is still sent. --}}
        @foreach ($plans as $plan)
            <input type="hidden" name="grants[{{ $plan->id }}][]" value="">
        @endforeach

        <div class="table-wrap">
            <table class="table plan-matrix">
                <thead>
                    <tr>
                        <th>Module</th>
                        @foreach ($plans as $plan)
                            <th class="plan-matrix-plan">
                                {{ $plan->name }}
                                <span class="text-xs text-muted">
                                    {{ $plan->isCustom() ? 'Custom · '.$plan->client?->name : 'General' }}{{ $plan->is_active ? '' : ' · withdrawn' }}
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($modules as $key => $module)
                        <tr>
                            <td>
                                <strong>{{ $module['label'] ?? $key }}</strong>
                                @if ($plans->every(fn ($plan) => ! $plan->grants($key)))
                                    <span class="badge badge-warning">Not in any plan</span>
                                @endif
                                @if (! empty($module['blurb']))
                                    <span class="text-xs text-muted" style="display:block">{{ $module['blurb'] }}</span>
                                @endif
                            </td>
                            @foreach ($plans as $plan)
                                <td class="plan-matrix-tick">
                                    <input type="checkbox" name="grants[{{ $plan->id }}][]" value="{{ $key }}"
                                           aria-label="{{ $module['label'] ?? $key }} in {{ $plan->name }}"
                                           @checked($plan->grants($key)) @disabled(! $canEdit)>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($canEdit)
            <div class="modal-actions" style="padding:14px 16px">
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        @endif
    </form>
@endsection
