{{-- One headline figure. $label, $value, optional $sub, $icon, $tone (success|info) --}}
<div class="stat">
    <div class="stat-icon {{ isset($tone) ? 'is-'.$tone : '' }}"><x-icon :name="$icon ?? 'grid'" :size="21" /></div>
    <div class="stat-body">
        <div class="stat-label">{{ $label }}</div>
        <div class="stat-value">{{ $value }}</div>
        @isset($sub)
            <span class="text-xs text-muted">{{ $sub }}</span>
        @endisset
    </div>
</div>
