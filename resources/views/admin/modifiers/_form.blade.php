{{--
    Add / Edit an add-on question, rendered straight into the modal body.

    The answers are edited here rather than behind a second screen: a question
    with no answers is not a half-finished thing to save, it is a question that
    would break every dish that asks it. See the controller.

    The repeating rows are wired by js/modifier-options.js, which clones the
    <template> below. Without JS the rows already present still submit and
    still save — only adding a new one needs the script.
--}}

@php
    $isNew = ! $modifier->exists;
    // Always at least one empty row to type into, so a brand-new question is
    // never a form with nothing on it.
    $rows = $options->isEmpty() ? collect([null]) : $options;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.modifiers.store') : route('admin.modifiers.update', $modifier) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    @if ($isNew)
        <input type="hidden" name="shop_id" value="{{ App\Support\CurrentShop::idForWrite() }}">
    @endif

    <div class="settings-grid">
        <div class="field">
            <label for="mod-name">Question</label>
            <input id="mod-name" type="text" name="name" class="form-control" required
                   value="{{ $modifier->name }}" autocomplete="off" aria-invalid="false"
                   maxlength="120" placeholder="Choose your crust">
        </div>

        <div class="field">
            <label for="mod-instruction">Hint</label>
            <input id="mod-instruction" type="text" name="instruction" class="form-control"
                   value="{{ $modifier->instruction }}" autocomplete="off" aria-invalid="false"
                   maxlength="160" placeholder="Pick one">
            <div class="form-hint">Shown under the question on the menu.</div>
        </div>

        <div class="field">
            <label for="mod-min">At least</label>
            <input id="mod-min" type="number" name="min_select" class="form-control" required
                   value="{{ $modifier->min_select ?? 0 }}" min="0" max="20" aria-invalid="false">
            <div class="form-hint">0 makes the question optional.</div>
        </div>

        <div class="field">
            <label for="mod-max">At most</label>
            <input id="mod-max" type="number" name="max_select" class="form-control"
                   value="{{ $modifier->max_select }}" min="1" max="20" aria-invalid="false"
                   placeholder="No limit">
            <div class="form-hint">
                Blank means no limit — as many as they like. Set both to 1 for a straight choice.
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- answers --}}

    <div class="form-section" data-modifier-options>
        <div class="form-section-title">Answers</div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:44%">Name</th>
                        <th style="width:20%">Price change</th>
                        <th style="width:14%">Default</th>
                        <th style="width:14%">Available</th>
                        <th class="col-action"></th>
                    </tr>
                </thead>

                <tbody data-option-body>
                    @foreach ($rows as $index => $option)
                        <tr data-option-row>
                            <td>
                                <label class="sr-only" for="opt-name-{{ $index }}">Answer name</label>
                                <input id="opt-name-{{ $index }}" type="text"
                                       name="options[{{ $index }}][name]" class="form-control"
                                       value="{{ $option?->name }}" autocomplete="off" aria-invalid="false"
                                       maxlength="120" placeholder="Thin crust">
                            </td>

                            <td>
                                <label class="sr-only" for="opt-price-{{ $index }}">Price change</label>
                                <input id="opt-price-{{ $index }}" type="number" step="0.01"
                                       name="options[{{ $index }}][price]" class="form-control"
                                       value="{{ $option ? (float) $option->price : 0 }}"
                                       aria-invalid="false" placeholder="0">
                            </td>

                            <td>
                                {{-- Hidden pair so an unticked box posts a 0
                                     rather than nothing at all. --}}
                                <label class="check">
                                    <input type="hidden" name="options[{{ $index }}][is_default]" value="0">
                                    <input type="checkbox" name="options[{{ $index }}][is_default]" value="1"
                                           @checked($option?->is_default)>
                                    <span class="sr-only">Default</span>
                                </label>
                            </td>

                            <td>
                                <label class="check">
                                    <input type="hidden" name="options[{{ $index }}][is_available]" value="0">
                                    <input type="checkbox" name="options[{{ $index }}][is_available]" value="1"
                                           @checked($option === null ? true : $option->is_available)>
                                    <span class="sr-only">Available</span>
                                </label>
                            </td>

                            <td class="col-action">
                                <button type="button" class="btn btn-icon is-danger" data-option-remove
                                        aria-label="Remove this answer">
                                    <x-icon name="trash" :size="15" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="padding:10px 0">
            <button type="button" class="btn btn-sm" data-option-add>
                <x-icon name="plus" :size="15" /> Add an answer
            </button>

            <span class="text-xs text-muted" style="margin-left:8px">
                A price change of 0 prints nothing on the menu. A negative one — &minus;20 for
                &ldquo;no cheese&rdquo; — keeps its sign.
            </span>
        </div>

        {{-- Cloned for each new row. @{{i}} is a Blade-escaped literal, replaced
             with the next index by the script. --}}
        <template data-option-template>
            <tr data-option-row>
                <td>
                    <input type="text" name="options[@{{i}}][name]" class="form-control"
                           autocomplete="off" aria-invalid="false" maxlength="120"
                           aria-label="Answer name" placeholder="Thin crust">
                </td>
                <td>
                    <input type="number" step="0.01" name="options[@{{i}}][price]"
                           class="form-control" value="0" aria-invalid="false"
                           aria-label="Price change">
                </td>
                <td>
                    <label class="check">
                        <input type="hidden" name="options[@{{i}}][is_default]" value="0">
                        <input type="checkbox" name="options[@{{i}}][is_default]" value="1">
                        <span class="sr-only">Default</span>
                    </label>
                </td>
                <td>
                    <label class="check">
                        <input type="hidden" name="options[@{{i}}][is_available]" value="0">
                        <input type="checkbox" name="options[@{{i}}][is_available]" value="1" checked>
                        <span class="sr-only">Available</span>
                    </label>
                </td>
                <td class="col-action">
                    <button type="button" class="btn btn-icon is-danger" data-option-remove
                            aria-label="Remove this answer">
                        <x-icon name="trash" :size="15" />
                    </button>
                </td>
            </tr>
        </template>
    </div>

    {{-- --------------------------------------------------- which dishes --}}

    <div class="form-section">
        <div class="form-section-title">Asked of</div>

        @if ($products->isEmpty())
            <p class="text-sm text-muted">There are no active menu items to attach this to yet.</p>
        @else
            <div class="form-hint" style="margin-bottom:8px">
                Tick every dish that asks this question. Editing an answer's price here changes it
                on all of them.
            </div>

            <div style="max-height:220px;overflow:auto;border:1px solid var(--border);
                        border-radius:var(--radius);padding:10px">
                {{-- Grouped outside the loop: Blade finds the end of an
                     @foreach by matching parentheses, and a closure with a
                     ?: inside the condition is a parse error rather than a
                     style preference. --}}
                @php
                    $grouped = $products->groupBy(function ($item) {
                        return $item->category?->name ?: 'Uncategorised';
                    });
                @endphp

                @foreach ($grouped as $group => $items)
                    <div class="form-label" style="margin-top:6px">{{ $group }}</div>

                    <div style="display:flex;flex-wrap:wrap;gap:10px 16px;margin-bottom:6px">
                        @foreach ($items as $item)
                            <label class="check">
                                <input type="checkbox" name="products[]" value="{{ $item->id }}"
                                       @checked(in_array($item->id, $attached, true))>
                                {{ $item->name }}
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create add-on' : 'Save changes' }}
        </button>
    </div>
</form>
