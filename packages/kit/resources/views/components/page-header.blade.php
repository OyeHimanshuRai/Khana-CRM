@props([
    'title',
    'subtitle' => null,
    // [label => url]; a null url is plain text, and the last is the current page.
    'crumbs' => [],
])

<div class="page-header">
    <div>
        <h1 class="page-title">{{ $title }}</h1>

        @if ($subtitle)
            <div class="page-sub">{{ $subtitle }}</div>
        @endif

        @if (! empty($crumbs))
            @php $last = array_key_last($crumbs); @endphp
            <nav aria-label="Breadcrumb" style="margin-top:6px">
                <ol class="breadcrumb">
                    @foreach ($crumbs as $label => $url)
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
