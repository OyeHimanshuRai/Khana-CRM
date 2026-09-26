@php
    use App\Support\CurrentTenant;

    /*
     | Which business the reader is working inside.
     |
     | Hidden entirely unless there is more than one to choose between, which
     | in practice means "hidden for everyone except a Super Admin on a
     | multi-company install". That is deliberate: ordinary staff belong to
     | exactly one company and there is nothing for them to switch to, so a
     | switcher would only ever suggest otherwise.
     */
    $tenants = CurrentTenant::accessible();
    $active = CurrentTenant::get();
@endphp

@if ($tenants->count() > 1)
    <div class="dropdown tenant-switcher">
        <button type="button" class="header-btn header-shop" data-dropdown-toggle
                aria-expanded="false" aria-label="Switch company">
            <x-icon name="grid" :size="16" />
            <span class="header-shop-name">{{ $active?->name ?? 'All companies' }}</span>
            <x-icon name="chevron-down" :size="14" />
        </button>

        <div class="dropdown-menu" style="width:280px">
            <div class="dropdown-header">
                <strong style="font-size:13.5px">Company</strong>
                <span class="text-xs text-muted" style="display:block">
                    Branches, stock and every ledger change with this.
                </span>
            </div>

            {{--
                Each option is its own tiny POST. A GET would make switching
                companies something a prefetch or a stray link could do.
            --}}
            @foreach ($tenants as $tenant)
                <form method="POST" action="{{ route('admin.tenants.switch') }}" data-ajax>
                    @csrf
                    <input type="hidden" name="tenant" value="{{ $tenant->id }}">
                    <button type="submit"
                            class="dropdown-item {{ $active?->id === $tenant->id ? 'is-active' : '' }}"
                            @disabled($active?->id === $tenant->id)>
                        <x-icon name="building" :size="16" />
                        <span style="min-width:0">
                            {{ $tenant->name }}
                            <span class="text-xs text-muted" style="display:block">
                                {{ $tenant->code }}@unless ($tenant->is_active) · suspended @endunless
                            </span>
                        </span>
                    </button>
                </form>
            @endforeach

            <div class="dropdown-divider"></div>

            <form method="POST" action="{{ route('admin.tenants.switch') }}" data-ajax>
                @csrf
                <input type="hidden" name="tenant" value="all">
                <button type="submit" class="dropdown-item {{ $active === null ? 'is-active' : '' }}"
                        @disabled($active === null)>
                    <x-icon name="grid" :size="16" />
                    <span style="min-width:0">
                        All companies
                        <span class="text-xs text-muted" style="display:block">
                            Consolidated view across {{ $tenants->count() }} companies
                        </span>
                    </span>
                </button>
            </form>

            @allows('settings.tenants.view')
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('admin.tenants.index') }}">
                    <x-icon name="settings" :size="16" /> Manage companies
                </a>
            @endallows
        </div>
    </div>
@endif
