{{--
    Read-only shop detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from renaming a tenant.
--}}

@php
    $logo = $shop->logoUrl();
    $currentShopId = App\Support\CurrentShop::id();
@endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $shop->name }}">
        @else
            <span class="text-xs text-muted">No logo</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $shop->name }}</div>

        @if ($shop->legal_name)
            <div class="text-sm text-muted">{{ $shop->legal_name }}</div>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $shop->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $shop->is_active ? 'Active' : 'Inactive' }}
            </span>
            <span class="badge badge-info">{{ $shop->code }}</span>

            @if ($shop->id === $currentShopId)
                <span class="badge badge-brand">You are working here</span>
            @endif

            @if ($shop->allow_negative_stock)
                <span class="badge badge-warning">Negative stock allowed</span>
            @endif

            @unless ($shop->block_expired_sale)
                <span class="badge badge-warning">Expired sale not blocked</span>
            @endunless
        </div>

        <dl class="sec-facts">
            <div><dt>GSTIN</dt><dd>{{ $shop->gstin ?: '—' }}</dd></div>
            <div><dt>PAN</dt><dd>{{ $shop->pan ?: '—' }}</dd></div>
            <div><dt>Licence</dt><dd>{{ $shop->licence_no ?: '—' }}</dd></div>
            <div><dt>State code</dt><dd>{{ $shop->state_code ?: '—' }}</dd></div>
            <div><dt>Phone</dt><dd>{{ $shop->phone ?: '—' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $shop->email ?: '—' }}</dd></div>
            <div><dt>Currency</dt><dd>{{ $shop->currency }}</dd></div>
            <div><dt>Timezone</dt><dd>{{ $shop->timezone }}</dd></div>
            <div>
                <dt>Next invoice</dt>
                <dd class="list-ref" style="font-size:13px">
                    {{ $shop->invoice_prefix }}/{{ $shop->code }}/{{ now()->format('Y') }}/{{ str_pad((string) $shop->invoice_next, 5, '0', STR_PAD_LEFT) }}
                </dd>
            </div>
            <div>
                <dt>Next receipt</dt>
                <dd class="list-ref" style="font-size:13px">
                    {{ $shop->pos_prefix }}/{{ $shop->code }}/{{ now()->format('Y') }}/{{ str_pad((string) $shop->pos_next, 5, '0', STR_PAD_LEFT) }}
                </dd>
            </div>
            <div><dt>Created</dt><dd>{{ $shop->created_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        @if ($shop->addressLine())
            <div style="margin-top:16px">
                <div class="form-label">Address</div>
                <p class="text-sm text-muted">{{ $shop->addressLine() }}{{ $shop->country ? ', '.$shop->country : '' }}</p>
            </div>
        @endif

        <div style="margin-top:16px">
            <div class="form-label">Business modules ({{ count($enabled) }} of {{ count($modules) }})</div>

            @if (empty($enabled))
                <p class="text-sm text-muted">
                    Nothing is switched on. This branch's dashboard and sidebar will be empty
                    for everybody, whatever their role.
                </p>
            @else
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                    @foreach ($modules as $key => $module)
                        <span class="badge {{ in_array($key, $enabled, true) ? 'badge-success' : '' }}"
                              title="{{ $module['blurb'] }}"
                              @unless (in_array($key, $enabled, true)) style="opacity:.5" @endunless>
                            {{ $module['label'] }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        <div style="margin-top:16px">
            <div class="form-label">Staff with access ({{ $shop->users_count }})</div>

            @if ($members->isEmpty())
                <p class="text-sm text-muted">
                    Nobody is assigned. Only Super Admins can reach this shop's data.
                </p>
            @else
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                    @foreach ($members as $member)
                        <span class="badge" title="{{ $member->email }}">
                            {{ $member->name }}@if ($member->pivot->is_default) · default @endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('settings.shops.edit')
        <a class="btn btn-primary" href="{{ route('admin.shops.edit', $shop) }}"
           data-modal="{{ route('admin.shops.edit', $shop) }}"
           data-modal-title="Edit Shop"
           data-modal-sub="{{ $shop->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
