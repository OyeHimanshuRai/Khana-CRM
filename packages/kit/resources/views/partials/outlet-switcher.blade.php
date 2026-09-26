@php
    use Khana\Kit\Tenancy\Context;
    use Khana\Kit\Tenancy\Directory;

    /*
     | Which outlet the page is working in, and where else the person may go.
     | A switcher with one option is chrome, not a control, so one outlet
     | shows as a label.
     */
    $context = app(Context::class);
    $outlets = $context->actor() ? $context->accessibleOutlets() : [];
    $names = $outlets ? app(Directory::class)->outletNames($outlets) : [];
    $active = $context->actor() ? $context->outletId() : null;
@endphp

@if (count($outlets) > 1 && Route::has('khana.outlet.switch'))
    <div class="dropdown shop-switcher">
        <button type="button" class="header-btn header-shop" data-dropdown-toggle aria-expanded="false" aria-label="Switch outlet">
            <x-khana::icon name="store" :size="16" />
            <span class="header-shop-name">{{ $active !== null ? ($names[$active] ?? 'Outlet') : 'All outlets' }}</span>
            <x-khana::icon name="chevron-down" :size="14" />
        </button>

        <div class="dropdown-menu" style="width:270px">
            <div class="dropdown-header">
                <strong style="font-size:13.5px">Working in</strong>
                <span class="text-xs text-muted" style="display:block">Everything below changes with this choice.</span>
            </div>

            {{-- A POST each: switching must not be something a prefetch can do. --}}
            @foreach ($outlets as $outlet)
                <form method="POST" action="{{ route('khana.outlet.switch') }}" data-ajax data-toast-success="false">
                    @csrf
                    <input type="hidden" name="outlet" value="{{ $outlet }}">
                    <button type="submit" class="dropdown-item {{ (string) $active === (string) $outlet ? 'is-active' : '' }}"
                            @disabled((string) $active === (string) $outlet)>
                        <x-khana::icon name="store" :size="16" />
                        <span style="min-width:0">{{ $names[$outlet] ?? 'Outlet' }}</span>
                    </button>
                </form>
            @endforeach

            @if ($context->canSeeAllOutlets())
                <div class="dropdown-divider"></div>

                <form method="POST" action="{{ route('khana.outlet.switch') }}" data-ajax data-toast-success="false">
                    @csrf
                    <input type="hidden" name="outlet" value="all">
                    <button type="submit" class="dropdown-item {{ $active === null ? 'is-active' : '' }}" @disabled($active === null)>
                        <x-khana::icon name="grid" :size="16" />
                        <span style="min-width:0">
                            All outlets
                            <span class="text-xs text-muted" style="display:block">Totals across your {{ count($outlets) }} outlets</span>
                        </span>
                    </button>
                </form>
            @endif
        </div>
    </div>
@elseif ($active !== null && isset($names[$active]))
    <span class="header-shop is-static">
        <x-khana::icon name="store" :size="16" />
        <span class="header-shop-name">{{ $names[$active] }}</span>
    </span>
@endif
