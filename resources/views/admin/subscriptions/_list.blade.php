{{--
    Swappable fragment: one row per business, with its plan and its state.

    The state badge is computed from the subscription's dates on render -
    there is no status column to read. See the subscriptions migration for
    why, and Subscription::state() for how.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Company</th>
                <th>Plan</th>
                <th>State</th>
                <th>Renews</th>
                <th>Usage</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tenants as $tenant)
                @php $sub = $tenant->subscription; @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.subscriptions.show', $tenant) }}"
                               data-modal="{{ route('admin.subscriptions.show', $tenant) }}"
                               data-modal-title="{{ $tenant->name }}"
                               data-modal-sub="Subscription & billing"
                               data-modal-size="lg">{{ $tenant->name }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">{{ $tenant->code }}</span>
                    </td>

                    <td class="text-sm">
                        @if ($sub?->plan)
                            {{ $sub->plan->name }}
                            <span class="text-xs text-muted" style="display:block">
                                {{ $sub->currency }} {{ number_format((float) $sub->price, 2) }}
                                / {{ $sub->billing_period === 'yearly' ? 'year' : 'month' }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        @if ($sub)
                            <span class="badge badge-{{ $sub->stateTone() }}">
                                <span class="badge-dot"></span> {{ $sub->stateLabel() }}
                            </span>
                        @else
                            <span class="badge badge-muted">No plan</span>
                            <span class="text-xs text-muted" style="display:block">nothing is limited</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($sub === null)
                            <span class="text-muted">—</span>
                        @elseif ($sub->ends_at === null)
                            Never
                            <span class="text-xs text-muted" style="display:block">perpetual</span>
                        @else
                            {{ $sub->ends_at->format('j M Y') }}
                            @php $left = $sub->daysLeft(); @endphp
                            <span class="text-xs {{ $left !== null && $left < 0 ? 'text-danger' : 'text-muted' }}"
                                  style="display:block">
                                @if ($left === null)
                                    &nbsp;
                                @elseif ($left < 0)
                                    {{ abs($left) }} day{{ abs($left) === 1 ? '' : 's' }} ago
                                @elseif ($left === 0)
                                    today
                                @else
                                    in {{ $left }} day{{ $left === 1 ? '' : 's' }}
                                @endif
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ number_format($tenant->shops_count) }} branch{{ $tenant->shops_count === 1 ? '' : 'es' }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ number_format($tenant->users_count) }} staff
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.subscriptions.show', $tenant) }}"
                               data-modal="{{ route('admin.subscriptions.show', $tenant) }}"
                               data-modal-title="{{ $tenant->name }}"
                               data-modal-sub="Subscription & billing"
                               data-modal-size="lg"
                               aria-label="View {{ $tenant->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($canEdit)
                                <a class="btn btn-icon" href="{{ route('admin.subscriptions.assign', $tenant) }}"
                                   data-modal="{{ route('admin.subscriptions.assign', $tenant) }}"
                                   data-modal-title="{{ $sub ? 'Change Plan' : 'Put on a Plan' }}"
                                   data-modal-sub="{{ $tenant->name }}"
                                   aria-label="Change plan for {{ $tenant->name }}">
                                    <x-icon name="tag" :size="15" />
                                </a>

                                @if ($sub)
                                    <a class="btn btn-icon" href="{{ route('admin.subscriptions.payment', $tenant) }}"
                                       data-modal="{{ route('admin.subscriptions.payment', $tenant) }}"
                                       data-modal-title="Record Payment"
                                       data-modal-sub="{{ $tenant->name }}"
                                       aria-label="Record a payment for {{ $tenant->name }}">
                                        <x-icon name="wallet" :size="15" />
                                    </a>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No businesses found</h3>
                            <p class="text-sm">Adjust the search or the state filter.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$tenants" :per-page="$perPage" :page-sizes="$pageSizes" label="businesses" />
