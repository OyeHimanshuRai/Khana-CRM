{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change.
--}}

@php
    $currentTenantId = App\Support\CurrentTenant::id();
@endphp

<div class="table-wrap">
    <table class="table table-list table-tenants">
        <thead>
            <tr>
                <th style="width:1%" class="col-hide-sm">Logo</th>
                <th>Company</th>
                <th class="col-hide-sm">Code</th>
                <th class="col-hide-sm">Location</th>
                <th class="col-hide-sm">GSTIN</th>
                <th class="col-hide-sm">Branches</th>
                <th>Status</th>
                <th class="col-hide-sm">License</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tenants as $tenant)
                @php $logo = $tenant->logoUrl(); @endphp
                <tr>
                    <td class="col-hide-sm">
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

                        {{-- Who to contact: the owner's name, and the company's email and mobile. --}}
                        @php
                            $contactName = $tenant->owner?->name;
                            $contactEmail = $tenant->email ?: $tenant->owner?->email;
                            $contactPhone = $tenant->phone ?: $tenant->owner?->mobile;
                        @endphp
                        @if ($contactName || $contactEmail || $contactPhone)
                            <div class="text-xs text-muted tenant-contact">
                                @if ($contactName)<span>{{ $contactName }}</span>@endif
                                @if ($contactEmail)<a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>@endif
                                @if ($contactPhone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactPhone) }}">{{ $contactPhone }}</a>@endif
                            </div>
                        @endif

                        {{-- Phones: the License column is out of sight, so the same thing sits under the name. --}}
                        @if ($canLicense)
                            @php $mlic = $tenant->latestLicense; $mb = $mlic?->badge(); @endphp
                            <div class="lic-inline">
                                <a href="{{ route('admin.tenants.license', $tenant) }}"
                                   data-modal="{{ route('admin.tenants.license', $tenant) }}"
                                   data-modal-title="License" data-modal-sub="{{ $tenant->name }}" data-modal-size="lg"
                                   class="badge badge-{{ $mb['tone'] ?? 'muted' }}"><span class="badge-dot"></span>
                                    License: {{ $mb['label'] ?? ($tenant->license_exempt ? 'Not required' : 'None') }}</a>
                                @if ($mlic && $mlic->status === \App\Models\License::PENDING)
                                    <form method="POST" action="{{ route('admin.licenses.act', [$mlic, 'approve']) }}" data-ajax data-refresh-list style="display:inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </td>

                    <td class="col-hide-sm"><span class="list-ref">{{ $tenant->code }}</span></td>

                    <td class="text-sm col-hide-sm">
                        {{ collect([$tenant->city, $tenant->state])->filter()->implode(', ') ?: '—' }}
                    </td>

                    <td class="text-sm col-hide-sm">{{ $tenant->gstin ?: '—' }}</td>

                    <td class="text-sm col-hide-sm">
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

                    <td class="col-hide-sm">
                        @php $lic = $tenant->latestLicense; @endphp
                        @if ($lic)
                            @php $licBadge = $lic->badge(); @endphp
                            <a href="{{ route('admin.tenants.license', $tenant) }}"
                               data-modal="{{ route('admin.tenants.license', $tenant) }}"
                               data-modal-title="License" data-modal-sub="{{ $tenant->name }}" data-modal-size="lg"
                               class="badge badge-{{ $licBadge['tone'] }}"><span class="badge-dot"></span> {{ $licBadge['label'] }}</a>
                            @if ($lic->expires_at)
                                <span class="text-xs text-muted" style="display:block">until {{ $lic->expires_at->format('j M Y') }}</span>
                            @endif
                            @if ($canLicense && $lic->status === \App\Models\License::PENDING)
                                <form method="POST" action="{{ route('admin.licenses.act', [$lic, 'approve']) }}" data-ajax data-refresh-list style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary" style="margin-top:4px">Approve</button>
                                </form>
                            @endif
                        @elseif ($tenant->license_exempt)
                            <span class="text-xs text-muted">Not required</span>
                        @else
                            <span class="badge badge-muted">None</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @if ($canLicense)
                                <a class="btn btn-icon" href="{{ route('admin.tenants.license', $tenant) }}"
                                   data-modal="{{ route('admin.tenants.license', $tenant) }}"
                                   data-modal-title="License" data-modal-sub="{{ $tenant->name }}" data-modal-size="lg"
                                   aria-label="License of {{ $tenant->name }}">
                                    <x-icon name="shield" :size="15" />
                                </a>
                            @endif

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

                            @if ($canCreate && $tenant->is_active && auth()->user()->isSuperAdmin() && ! session(\App\Http\Controllers\Admin\ImpersonationController::SESSION_KEY))
                                <form method="POST" action="{{ route('admin.tenants.impersonate', $tenant) }}"
                                      onsubmit="return confirm('Sign in as the owner of “{{ $tenant->name }}”? You can exit any time from the top banner.')">
                                    @csrf
                                    <button type="submit" class="btn btn-icon" title="Login as owner"
                                            aria-label="Login as owner of {{ $tenant->name }}">
                                        <x-icon name="user" :size="15" />
                                    </button>
                                </form>
                            @endif

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
                    <td colspan="9">
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
