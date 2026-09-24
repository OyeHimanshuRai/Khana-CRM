@props([
    /* Form field name, e.g. "desktop_image". */
    'name',
    'label',
    /* image | video - decides whether the preview is an <img> or a <video>. */
    'kind' => 'image',
    /* accept attribute for the picker. */
    'accept' => 'image/png,image/jpeg',
    /* Human formats line, e.g. "SVG, PNG, JPG". */
    'formats' => null,
    /* Size guidance, e.g. "Maximum 800 × 400px". */
    'note' => null,
    /* Public URL of what is stored now, or null. */
    'url' => null,
    /* Endpoint the Remove button posts to; null hides it (create forms). */
    'removeUrl' => null,
])

@php $id = 'media-'.$name; @endphp

{{--
    One media slot: drop zone, preview and the two controls.

    Behaviour is delegated from crud-forms.js - the whole block is injected
    by modal.js, so a <script> in here would never run.
--}}
<div class="media-field" data-media-field data-media-kind="{{ $kind }}"
     data-stored-url="{{ $url }}">
    <div class="media-field-head">
        <span class="form-label" style="margin:0">{{ $label }}</span>
        @if ($formats)
            <span class="media-formats">{{ $formats }}</span>
        @endif
    </div>

    {{--
        The zone is the label for the input, so a click opens the picker and
        a drop is caught by the same element. Keyboard users get there
        through the input itself, which is visually hidden but focusable.
    --}}
    <label class="media-dropzone {{ $url ? 'has-media' : '' }}" for="{{ $id }}" data-dropzone>
        <span class="media-preview" data-file-preview>
            @if ($url && $kind === 'video')
                <video src="{{ $url }}" controls preload="metadata" playsinline></video>
            @elseif ($url)
                <img src="{{ $url }}" alt="{{ $label }}">
            @endif
        </span>

        <span class="media-empty" data-dropzone-hint>
            <x-icon :name="$kind === 'video' ? 'panel-left' : 'package'" :size="22" />
            <span class="media-empty-title">
                Drag &amp; drop, or <span class="media-link">choose a file</span>
            </span>
            @if ($note)
                <span class="text-xs text-muted">{{ $note }}</span>
            @endif
        </span>

        <input id="{{ $id }}" type="file" name="{{ $name }}" class="sr-only"
               accept="{{ $accept }}" data-file-input>
    </label>

    <div class="media-actions">
        {{-- Same picker as the zone; a second, more obvious way in. --}}
        <button type="button" class="btn btn-sm" data-media-pick>
            <x-icon name="edit" :size="13" /> {{ $url ? 'Replace' : 'Choose' }}
        </button>

        @if ($removeUrl)
            <button type="button" class="btn btn-sm btn-ghost" data-media-remove
                    data-remove-url="{{ $removeUrl }}"
                    @unless ($url) hidden @endunless>
                <x-icon name="trash" :size="13" /> Remove
            </button>
        @endif

        {{-- Clears a file picked but not yet uploaded, without touching
             whatever is already stored. --}}
        <button type="button" class="btn btn-sm btn-ghost" data-media-clear hidden>
            <x-icon name="x" :size="13" /> Undo choice
        </button>
    </div>
</div>
