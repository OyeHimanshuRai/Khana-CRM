{{--
    Swappable fragment: the plan table plus its pagination.

    `$live` is subscribers on the plan *now*, which is not the same as
    `subscriptions_count` - that counts history, including every row a plan
    change left behind. Showing the latter as "subscribers" would say a
    withdrawn plan has customers it lost years ago.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Plan</th>
                <th>Price</th>
                <th>Includes</th>
                <th>Limits</th>
                <th>Subscribers</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($plans as $plan)
                @php
                    $subscribers = $live[$plan->id] ?? 0;
                    $granted = count($plan->moduleKeys());
                    $everything = $plan->modules === null;
                @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.plans.show', $plan) }}"
                               data-modal="{{ route('admin.plans.show', $plan) }}"
                               data-modal-title="{{ $plan->name }}"
                               data-modal-sub="Plan details"
                               data-modal-size="lg">{{ $plan->name }}</a>
                        </strong>
                        @if ($plan->blurb)
                            <span class="text-xs text-muted" style="display:block">{{ $plan->blurb }}</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        <strong>{{ $plan->currency }} {{ number_format((float) $plan->monthly_price, 2) }}</strong>
                        <span class="text-xs text-muted" style="display:block">a month</span>
                        @if ($plan->yearly_price !== null)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $plan->currency }} {{ number_format((float) $plan->yearly_price, 2) }} a year
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($everything)
                            <span class="badge badge-success">Every module</span>
                            <span class="text-xs text-muted" style="display:block">
                                including any added later
                            </span>
                        @elseif ($granted === 0)
                            <span class="badge badge-danger">No modules</span>
                        @else
                            {{ $granted }} module{{ $granted === 1 ? '' : 's' }}
                            <span class="text-xs text-muted" style="display:block">
                                {{ collect($plan->moduleKeys())
                                    ->map(fn ($k) => $modules[$k]['label'] ?? $k)
                                    ->take(3)->implode(', ') }}{{ $granted > 3 ? '…' : '' }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $plan->max_shops === null ? 'Branches: any' : 'Branches: '.$plan->max_shops }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $plan->max_users === null ? 'Staff: any' : 'Staff: '.$plan->max_users }}
                        </span>
                    </td>

                    <td class="text-sm">
                        {{ number_format($subscribers) }}
                        @if ($plan->trial_days > 0)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $plan->trial_days }}-day trial
                            </span>
                        @endif
                    </td>

                    <td>
                        @allows('settings.plans.edit')
                            <form method="POST" action="{{ route('admin.plans.status', $plan) }}"
                                  data-ajax data-refresh-list style="display:inline"
                                  @if ($plan->is_active && $subscribers > 0)
                                      onsubmit="return confirm('Withdraw “{{ $plan->name }}” from sale? The {{ $subscribers }} business(es) on it keep it — it simply stops being offered to anybody new.')"
                                  @endif>
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $plan->is_active ? 'is-on' : '' }}"
                                        title="{{ $plan->is_active ? 'Click to withdraw from sale' : 'Click to put on sale' }}">
                                    <span class="badge-dot"></span>
                                    {{ $plan->is_active ? 'On sale' : 'Withdrawn' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $plan->is_active ? 'badge-success' : 'badge-muted' }}">
                                <span class="badge-dot"></span>
                                {{ $plan->is_active ? 'On sale' : 'Withdrawn' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.plans.show', $plan) }}"
                               data-modal="{{ route('admin.plans.show', $plan) }}"
                               data-modal-title="{{ $plan->name }}"
                               data-modal-sub="Plan details"
                               data-modal-size="lg"
                               aria-label="View {{ $plan->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('settings.plans.edit')
                                <a class="btn btn-icon" href="{{ route('admin.plans.edit', $plan) }}"
                                   data-modal="{{ route('admin.plans.edit', $plan) }}"
                                   data-modal-title="Edit Plan"
                                   data-modal-sub="{{ $plan->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $plan->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('settings.plans.delete')
                                @if ($plan->subscriptions_count === 0)
                                    <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete “{{ $plan->name }}”? Nobody has ever been on it, so nothing is lost.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $plan->name }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endif
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No plans yet</h3>
                            <p class="text-sm">
                                Nothing is limited until a business is put on a plan — an install with no
                                plans behaves exactly as it always has.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$plans" :per-page="$perPage" :page-sizes="$pageSizes" label="plans" />
