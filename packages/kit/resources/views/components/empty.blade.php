@props(['icon' => 'inbox', 'title' => 'Nothing here yet', 'text' => null])

{{-- A to-do, not a blank page: say what is missing and offer the next step. --}}
<div {{ $attributes->class(['empty']) }}>
    <x-khana::icon :name="$icon" :size="30" />
    <div class="empty-title" style="font-weight:600;color:var(--heading)">{{ $title }}</div>
    @if ($text)
        <div class="text-sm text-muted">{{ $text }}</div>
    @endif
    @if (! $slot->isEmpty())
        <div style="margin-top:12px">{{ $slot }}</div>
    @endif
</div>
