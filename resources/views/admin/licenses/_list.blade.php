{{-- Swappable fragment: one row per license. The key itself is never shown, only its hint. --}}
<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>License</th>
                <th>Customer</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Expires</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($licenses as $license)
                @php $badge = $license->badge(); $left = $license->daysLeft(); @endphp
                <tr>
                    <td>
                        <a href="{{ route('admin.licenses.show', $license) }}"
                           data-modal="{{ route('admin.licenses.show', $license) }}"
                           data-modal-title="License"
                           data-modal-sub="{{ $license->key_hint }}"
                           data-modal-size="lg"><strong><code>{{ $license->key_hint }}</code></strong></a>
                        <span class="text-xs text-muted" style="display:block">Created {{ $license->created_at?->format('j M Y') }}</span>
                    </td>
                    <td class="text-sm">
                        @if ($license->tenant)
                            {{ $license->tenant->name }}
                            <span class="text-xs text-muted" style="display:block">{{ $license->tenant->code }}</span>
                        @else
                            <span class="text-muted">Not assigned</span>
                        @endif
                    </td>
                    <td class="text-sm">{{ $license->plan?->name ?? '—' }}</td>
                    <td>
                        <span class="badge badge-{{ $badge['tone'] }}"><span class="badge-dot"></span> {{ $badge['label'] }}</span>
                    </td>
                    <td class="text-sm">
                        @if ($license->expires_at)
                            {{ $license->expires_at->format('j M Y') }}
                            <span class="text-xs {{ $left !== null && $left < 0 ? 'text-danger' : 'text-muted' }}" style="display:block">
                                {{ $left < 0 ? abs($left).' day'.(abs($left) === 1 ? '' : 's').' ago' : ($left === 0 ? 'today' : 'in '.$left.' day'.($left === 1 ? '' : 's')) }}
                            </span>
                        @else
                            <span class="text-muted">{{ $license->validity_days }} days from approval</span>
                        @endif
                    </td>
                    <td class="col-action">
                        <div class="row-actions">
                            @if ($canEdit && $license->status === \App\Models\License::PENDING)
                                <form method="POST" action="{{ route('admin.licenses.act', [$license, 'approve']) }}" data-ajax data-refresh-list style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                </form>
                            @endif
                            <a class="btn btn-icon" href="{{ route('admin.licenses.show', $license) }}"
                               data-modal="{{ route('admin.licenses.show', $license) }}"
                               data-modal-title="License" data-modal-sub="{{ $license->key_hint }}" data-modal-size="lg"
                               aria-label="Open {{ $license->key_hint }}">
                                <x-icon name="search" :size="15" />
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="shield" :size="28" />
                            <h3>No licenses found</h3>
                            <p class="text-sm">Generate a key, or change the filters.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$licenses" :per-page="$perPage" :page-sizes="$pageSizes" label="licenses" />
