{{--
    The item-lines editor: "these items, this much of each".

    Receiving, issuing, wastage, an order, a recipe - all the same table.
    Read on the server by App\Support\Lines.

        @include('partials.lines', [
            'catalogue' => $catalogue,           // App\Support\Lines::catalogue()
            'rows' => $rows ?? [],               // what to start with
            'columns' => ['price', 'batch'],     // any of: price, batch, note, optional
        ])

    Picking an item fills its units (its own packs included). Rows left
    blank are ignored, so there is always a spare one and "Add a row" is
    only for a long delivery. Without JavaScript the rows already drawn
    still work.
--}}
@php
    $columns = $columns ?? [];
    $rows = array_values(old('lines', $rows ?? []));
    $rows = array_pad($rows, max(count($rows) + 1, $blank ?? 4), []);
    $has = fn (string $column) => in_array($column, $columns, true);
@endphp

@error('lines')
    <div class="alert alert-danger" role="alert" style="margin-bottom:12px">{{ $message }}</div>
@enderror

<div data-lines>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width:220px">Item</th>
                    <th style="width:110px">Quantity</th>
                    <th style="width:150px">Unit</th>
                    @if ($has('price')) <th style="width:140px">Price per unit (&#8377;)</th> @endif
                    @if ($has('batch')) <th style="width:130px">Batch</th><th style="width:150px">Expires</th> @endif
                    @if ($has('note')) <th>Note</th> @endif
                    @if ($has('optional')) <th style="width:90px">Optional</th> @endif
                </tr>
            </thead>
            <tbody data-lines-body>
                @foreach ($rows as $i => $row)
                    @php
                        $picked = $catalogue[(int) ($row['item'] ?? 0)] ?? null;
                        $problem = $errors->first("lines.$i.item") ?: $errors->first("lines.$i.qty") ?: $errors->first("lines.$i.expiry");
                    @endphp
                    <tr data-line>
                        <td>
                            <select class="form-control" name="lines[{{ $i }}][item]" data-line-item aria-label="Item">
                                <option value="">Choose an item…</option>
                                @foreach ($catalogue as $id => $item)
                                    <option value="{{ $id }}" @selected((int) ($row['item'] ?? 0) === $id)>{{ $item['name'] }}</option>
                                @endforeach
                            </select>
                            @if ($problem) <div class="field-error">{{ $problem }}</div> @endif
                        </td>
                        <td>
                            <input class="form-control" name="lines[{{ $i }}][qty]" value="{{ $row['qty'] ?? '' }}"
                                   inputmode="decimal" autocomplete="off" aria-label="Quantity">
                        </td>
                        <td>
                            <select class="form-control" name="lines[{{ $i }}][unit]" data-line-unit aria-label="Unit">
                                @foreach ($picked['units'] ?? [] as $code => $label)
                                    <option value="{{ $code }}" @selected(($row['unit'] ?? '') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        @if ($has('price'))
                            <td>
                                <input class="form-control" name="lines[{{ $i }}][price]" value="{{ $row['price'] ?? '' }}"
                                       inputmode="decimal" autocomplete="off" aria-label="Price per unit" data-line-price>
                            </td>
                        @endif
                        @if ($has('batch'))
                            <td>
                                <input class="form-control" name="lines[{{ $i }}][batch_no]" value="{{ $row['batch_no'] ?? '' }}"
                                       aria-label="Batch number" data-line-batch @disabled($picked && ! $picked['expiry'])>
                            </td>
                            <td>
                                <input class="form-control" type="date" name="lines[{{ $i }}][expiry]" value="{{ $row['expiry'] ?? '' }}"
                                       aria-label="Expiry date" data-line-batch @disabled($picked && ! $picked['expiry'])>
                            </td>
                        @endif
                        @if ($has('note'))
                            <td><input class="form-control" name="lines[{{ $i }}][note]" value="{{ $row['note'] ?? '' }}" aria-label="Note"></td>
                        @endif
                        @if ($has('optional'))
                            <td style="text-align:center">
                                <input type="checkbox" name="lines[{{ $i }}][optional]" value="1" @checked($row['optional'] ?? false)
                                       aria-label="Optional ingredient">
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="padding:10px 16px">
        <button type="button" class="btn btn-sm btn-ghost" data-lines-add hidden>
            <x-khana::icon name="plus" :size="14" /> Add a row
        </button>
    </div>

    <script type="application/json" data-lines-catalogue>@json($catalogue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
</div>

@once
    @push('scripts')
        <script>
            /* The item-lines editor - see partials/lines.blade.php. */
            (function () {
                document.querySelectorAll('[data-lines]').forEach(function (root) {
                    var catalogue = JSON.parse(root.querySelector('[data-lines-catalogue]').textContent || '{}');
                    var body = root.querySelector('[data-lines-body]');
                    var add = root.querySelector('[data-lines-add]');

                    function fill(row, keep) {
                        var item = catalogue[row.querySelector('[data-line-item]').value];
                        var unit = row.querySelector('[data-line-unit]');
                        var chosen = keep ? unit.value : '';

                        unit.innerHTML = '';

                        Object.keys(item ? item.units : {}).forEach(function (code) {
                            var option = document.createElement('option');
                            option.value = code;
                            option.textContent = item.units[code];
                            unit.appendChild(option);
                        });

                        if (chosen && item && item.units[chosen]) {
                            unit.value = chosen;
                        } else if (item) {
                            // Kilos, litres or pieces are what people buy in.
                            unit.value = item.base === 'g' ? 'kg' : (item.base === 'ml' ? 'l' : 'pc');
                        }

                        row.querySelectorAll('[data-line-batch]').forEach(function (input) {
                            input.disabled = !!item && !item.expiry;
                            if (input.disabled) { input.value = ''; }
                        });

                        var price = row.querySelector('[data-line-price]');
                        if (price) {
                            price.placeholder = item && item.price !== '0.00' ? 'Last ' + item.price : '';
                        }
                    }

                    body.addEventListener('change', function (event) {
                        if (event.target.matches('[data-line-item]')) {
                            fill(event.target.closest('[data-line]'), false);
                        }
                    });

                    body.querySelectorAll('[data-line]').forEach(function (row) { fill(row, true); });

                    add.hidden = false;
                    add.addEventListener('click', function () {
                        var rows = body.querySelectorAll('[data-line]');
                        var copy = rows[rows.length - 1].cloneNode(true);
                        var index = rows.length;

                        copy.querySelectorAll('[name]').forEach(function (input) {
                            input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
                            if (input.type === 'checkbox') { input.checked = false; } else { input.value = ''; }
                        });
                        copy.querySelectorAll('.field-error').forEach(function (error) { error.remove(); });

                        body.appendChild(copy);
                        fill(copy, false);
                        copy.querySelector('[data-line-item]').focus();
                    });
                });
            })();
        </script>
    @endpush
@endonce
