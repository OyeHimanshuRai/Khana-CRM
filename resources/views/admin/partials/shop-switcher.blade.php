@php
    use App\Support\CurrentShop;

    /*
     | Which shop the reader is working in, and what else they may switch to.
     |
     | Hidden entirely when there is exactly one shop: a switcher with one
     | option is chrome, not a control. It also stays hidden for an account
     | with no shop at all - that user has an access problem, and the
     | dashboard says so far more usefully than an empty dropdown would.
     */
    $shops = CurrentShop::accessible();
    $active = CurrentShop::get();
@endphp

@if ($shops->count() > 1)
    <div class="dropdown shop-switcher">
        <button type="button" class="header-btn header-shop" data-dropdown-toggle
                aria-expanded="false" aria-label="Switch shop">
            <x-icon name="building" :size="16" />
            <span class="header-shop-name">{{ $active?->name ?? 'All shops' }}</span>
            <x-icon name="chevron-down" :size="14" />
        </button>

        <div class="dropdown-menu" style="width:270px">
            <div class="dropdown-header">
                <strong style="font-size:13.5px">Working in</strong>
                <span class="text-xs text-muted" style="display:block">
                    Everything below changes with this choice.
                </span>
            </div>

            {{--
                Each option is its own tiny POST. A GET would make switching
                shops something a prefetch or a stray link could do.
            --}}
            @foreach ($shops as $shop)
                <form method="POST" action="{{ route('admin.shops.switch') }}" data-ajax>
                    @csrf
                    <input type="hidden" name="shop" value="{{ $shop->id }}">
                    <button type="submit"
                            class="dropdown-item {{ $active?->id === $shop->id ? 'is-active' : '' }}"
                            @disabled($active?->id === $shop->id)>
                        <x-icon name="building" :size="16" />
                        <span style="min-width:0">
                            {{ $shop->name }}
                            <span class="text-xs text-muted" style="display:block">
                                {{ $shop->code }}@unless ($shop->is_active) · inactive @endunless
                            </span>
                        </span>
                    </button>
                </form>
            @endforeach

            <div class="dropdown-divider"></div>

            <form method="POST" action="{{ route('admin.shops.switch') }}" data-ajax>
                @csrf
                <input type="hidden" name="shop" value="all">
                <button type="submit" class="dropdown-item {{ $active === null ? 'is-active' : '' }}"
                        @disabled($active === null)>
                    <x-icon name="grid" :size="16" />
                    <span style="min-width:0">
                        All shops
                        <span class="text-xs text-muted" style="display:block">
                            Consolidated view across your {{ $shops->count() }} shops
                        </span>
                    </span>
                </button>
            </form>

            @allows('settings.shops.view')
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('admin.shops.index') }}">
                    <x-icon name="settings" :size="16" /> Manage shops
                </a>
            @endallows
        </div>
    </div>
@elseif ($active)
    {{-- Single shop: show it as a label so the paperwork's origin is never
         in doubt, but give it nothing to click. --}}
    <span class="header-shop is-static" title="{{ $active->addressLine() }}">
        <x-icon name="building" :size="16" />
        <span class="header-shop-name">{{ $active->name }}</span>
    </span>
@endif
