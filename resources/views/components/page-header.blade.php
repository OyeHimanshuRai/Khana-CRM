@props([
    'title',
    'subtitle' => null,
    /*
     | Breadcrumb trail as [label => url]. A null url renders as plain text,
     | and the last entry is always marked as the current page. "Home" is
     | prepended automatically.
     */
    'crumbs' => [],
])

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $title }}</h1>

        @if ($subtitle)
            <div class="page-sub">{{ $subtitle }}</div>
        @endif

        @if (! empty($crumbs))
            @php
                $trail = array_merge(['Home' => route('admin.dashboard')], $crumbs);
                $last = array_key_last($trail);
            @endphp

            <nav aria-label="Breadcrumb" style="margin-top:6px">
                <ol class="breadcrumb">
                    @foreach ($trail as $label => $url)
                        <li>
                            @if ($url && $label !== $last)
                                <a href="{{ $url }}">{{ $label }}</a>
                            @else
                                <span @if ($label === $last) aria-current="page" @endif>{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
    </div>

    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
