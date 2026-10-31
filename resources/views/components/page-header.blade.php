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

@php
    /*
     | A subtitle is either a fact about what is on screen ("51 products in the
     | catalogue", a shop and a date) or a sentence explaining what the screen
     | is for. The first is read every time and stays under the title. The
     | second is read once, and is what an info icon is for - leaving it in
     | place costs every visit a line of height and a sentence the regular user
     | skips. It is told apart by what it is: a sentence ends in a full stop.
     */
    $text = $subtitle ? trim((string) $subtitle) : '';
    $explains = $text !== '' && mb_strlen($text) > 60 && str_ends_with($text, '.');
@endphp

<div class="page-header">
    <div>
        {{-- The trail sits above the title, small and quiet: where you are, not what this is. --}}
        @if (! empty($crumbs))
            @php
                $trail = array_merge(['Home' => auth()->user()?->homeUrl() ?? route('admin.dashboard')], $crumbs);
                $last = array_key_last($trail);
            @endphp

            <nav aria-label="Breadcrumb" class="page-crumbs">
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

        <h1 class="page-title">
            {{ $title }}
            @if ($explains)
                <x-info label="About this page">{{ $text }}</x-info>
            @endif
        </h1>

        @if ($text !== '' && ! $explains)
            <div class="page-sub">{{ $text }}</div>
        @endif
    </div>

    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
