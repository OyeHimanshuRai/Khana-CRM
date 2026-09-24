@props([
    'name',
    /* Field definition from config/company_settings.php. */
    'field',
    'value' => null,
    /* Public URL of the stored file, for type "image". */
    'fileUrl' => null,
    /* True when a secret already has a stored value. */
    'hasSecret' => false,
    /* Endpoint the Remove button posts to, for type "image". */
    'removeUrl' => null,
])

@php
    $type = $field['type'] ?? 'text';
    $id = 'set-'.$name;
    $full = ($field['width'] ?? null) === 'full' || in_array($type, ['textarea', 'image'], true);
@endphp

<div class="field {{ $full ? 'field-full' : '' }} {{ $type === 'checkbox' ? 'field-checkbox' : '' }}">
    @unless ($type === 'checkbox')
        <label for="{{ $id }}">{{ $field['label'] }}</label>
    @endunless

    @switch($type)
        @case('checkbox')
            {{--
                Same shape as every other boolean field in this app (see
                e.g. warehouses/_form.blade.php): a hidden 0 so an unchecked
                box still submits something, then the real checkbox as 1.
                CompanySettings::rules() requires exactly one of those.
            --}}
            <label class="check">
                <input type="hidden" name="{{ $name }}" value="0">
                <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1"
                       @checked((string) $value === '1')>
                {{ $field['label'] }}
            </label>
            @break
        @case('textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" class="form-control"
                      placeholder="{{ $field['placeholder'] ?? '' }}"
                      aria-invalid="false">{{ $value }}</textarea>
            @break

        @case('select')
            <select id="{{ $id }}" name="{{ $name }}" class="form-control" aria-invalid="false">
                @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>
                        {{ $optionLabel }}
                    </option>
                @endforeach
            </select>
            @break

        @case('secret')
            {{-- Write-only: never pre-filled, blank on submit keeps the stored value. --}}
            <div class="pw-wrap">
                <input id="{{ $id }}" type="password" name="{{ $name }}" class="form-control"
                       autocomplete="new-password"
                       placeholder="{{ $hasSecret ? '••••••••  (unchanged)' : 'Not set' }}"
                       aria-invalid="false">
                <button type="button" class="pw-toggle" data-toggle-password
                        aria-controls="{{ $id }}" aria-pressed="false">Show</button>
            </div>
            @break

        @case('image')
            <div class="setting-image" data-setting-file="{{ $name }}">
                <div class="setting-image-preview" data-file-preview>
                    @if ($fileUrl)
                        <img src="{{ $fileUrl }}" alt="{{ $field['label'] }} preview">
                    @else
                        <span class="text-xs text-muted">No image</span>
                    @endif
                </div>

                <div class="setting-image-controls">
                    <input id="{{ $id }}" type="file" name="{{ $name }}"
                           accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon"
                           data-file-input>

                    @if (! empty($field['note']))
                        <div class="form-hint">{{ $field['note'] }}</div>
                    @endif

                    <button type="button" class="btn btn-sm btn-ghost" data-file-remove
                            data-remove-url="{{ $removeUrl ?? '' }}"
                            @unless ($fileUrl) hidden @endunless>
                        <x-icon name="trash" :size="13" /> Remove
                    </button>
                </div>
            </div>
            @break

        @default
            {{-- form-control explicitly, so a type added to the config later
                 is styled even if theme.css has not learnt about it yet. --}}
            <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" class="form-control"
                   value="{{ $value }}"
                   placeholder="{{ $field['placeholder'] ?? '' }}"
                   aria-invalid="false">
    @endswitch

    @if (! empty($field['hint']))
        <div class="form-hint">{{ $field['hint'] }}</div>
    @endif
</div>
