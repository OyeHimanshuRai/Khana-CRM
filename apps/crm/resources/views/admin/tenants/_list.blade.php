{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change.
--}}

@php
    $currentTenantId = App\Support\CurrentTenant::id();
@endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Logo</th>
                <th>Company</th>
                <th>Code</th>
                <th>Location</th>
                <th>GSTIN</th>
                <th>Branches</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tenants as $tenant)
                @php $logo = $tenant->logoUrl(); @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $tenant->name }}">
                            @else
                                {{ $tenant->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.tenants.show', $tenant) }}"
                               data-modal="{{ route('admin.tenants.show', $tenant) }}"
                               data-modal-title="{{ $tenant->name }}"
                               data-modal-sub="Company details"
                               data-modal-size="lg">{{ $tenant->name }}</a>
                        </strong>

                        @if ($tenant->id === $currentTenantId)
                            {{-- Which business the reader is inside, so a
                                 destructive action here is never a surprise. --}}
                            <span class="badge badge-brand" style="margin-left:6px">Current</span>
                        @endif

                        @if ($tenant->legal_name)
                            <span class="text-xs text-muted" style="display:block">{{ $tenant->legal_name }}</span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $tenant->code }}</span></td>

                    <td class="text-sm">
                        {{ collect([$tenant->city, $tenant->state])->filter()->implode(', ') ?: '—' }}
                    </td>

                    <td class="text-sm">{{ $tenant->gstin ?: '—' }}</td>

                    <td class="text-sm">
                        {{ number_format($tenant->shops_count) }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ number_format($tenant->users_count) }} staff
                        </span>
                    </td>

                    <td>
                        @if ($canCreate)
                            {{-- Suspension is a platform action, so the toggle
                                 only exists for whoever runs the platform. --}}
                            <form method="POST" action="{{ route('admin.tenants.status', $tenant) }}"
                                  data-ajax data-refresh-list style="display:inline"
                                  @if ($tenant->is_active)
                                      onsubmit="return confirm('Suspend “{{ $tenant->name }}”? Its staff will be signed out and unable to trade. Every record is kept.')"
                                  @endif>
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $tenant->is_active ? 'is-on' : '' }}"
                                        title="{{ $tenant->is_active ? 'Click to suspend' : 'Click to reinstate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $tenant->is_active ? 'Trading' : 'Suspended' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $tenant->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $tenant->is_active ? 'Trading' : 'Suspended' }}
                            </span>
                        @endif

                        @if (! $tenant->is_active && $tenant->suspend_reason)
                            <span class="text-xs text-muted" style="display:block">{{ $tenant->suspend_reason }}</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.tenants.show', $tenant) }}"
                               data-modal="{{ route('admin.tenants.show', $tenant) }}"
                               data-modal-title="{{ $tenant->name }}"
                               data-modal-sub="Company details"
                               data-modal-size="lg"
                               aria-label="View {{ $tenant->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('settings.tenants.edit')
                                <a class="btn btn-icon" href="{{ route('admin.tenants.edit', $tenant) }}"
                                   data-modal="{{ route('admin.tenants.edit', $tenant) }}"
                                   data-modal-title="Edit Company"
                                   data-modal-sub="{{ $tenant->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $tenant->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @if ($canCreate)
                                @allows('settings.tenants.delete')
                                    <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Remove “{{ $tenant->name }}”? Suspending it is almost always the right answer instead — that keeps the business able to come back.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Remove {{ $tenant->name }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No companies found</h3>
                            <p class="text-sm">Adjust the search, or onboard the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$tenants" :per-page="$perPage" :page-sizes="$pageSizes" label="companies" />
