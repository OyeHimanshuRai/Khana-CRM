{{--
    Stock and cost for one dish, as ERP has it. A fragment: the item's page
    asks for it once it has drawn (see _erp), and ErpPanelController::stock
    builds it.

    $state is one of:
      unreachable  ERP could not be read. The rest of the page is unaffected.
      unknown      ERP has not been told about this dish.
      no_recipe    ERP knows the dish and has nothing to work a cost from.
      costed       $rows holds one entry for the dish, or one per size, named
                   the way this menu names them.

    A size's figures can be null inside a costed dish - that size has no
    recipe of its own yet - and are drawn as a dash rather than as zero,
    which would read as "costs nothing" and "none left".
--}}

@if ($state === 'unreachable')
    <x-khana::unreachable style="margin-bottom:0" />
@elseif ($state === 'unknown')
    <p class="text-sm text-muted" style="margin:0">ERP has not heard of this dish yet.</p>
@elseif ($state === 'no_recipe')
    <p class="text-sm text-muted" style="margin:0">
        No recipe yet in ERP. Once the dish has one, this shows how many portions the stock covers and
        what each one costs.
    </p>
@else
    @php
        $portions = fn (?int $count) => $count === null ? '—' : number_format($count).' '.Str::plural('portion', $count);
        $cost = fn (?float $rupees) => $rupees === null ? '—' : '₹'.number_format($rupees, 2);
        $percent = fn (?float $share) => $share === null ? '—' : rtrim(rtrim(number_format($share, 2), '0'), '.').'%';

        // A dish that comes one way is a row with no size name.
        $single = count($rows) === 1 && $rows[0]['name'] === null;
    @endphp

    @if ($single)
        @php $row = $rows[0]; @endphp

        <dl class="sec-facts">
            <div><dt>Stock covers</dt><dd>{{ $portions($row['portions']) }}</dd></div>
            <div><dt>Cost of a portion</dt><dd>{{ $cost($row['cost']) }}</dd></div>
            <div><dt>Food cost</dt><dd>{{ $percent($row['percent']) }}</dd></div>
            <div>
                <dt>In ERP</dt>
                <dd>
                    <span class="badge {{ $row['on_sale'] ? 'badge-success' : 'badge-danger' }}">
                        {{ $row['on_sale'] ? 'On sale' : 'Off sale' }}
                    </span>
                </dd>
            </div>
        </dl>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th class="num">Stock covers</th>
                        <th class="num">Cost of a portion</th>
                        <th class="num">Food cost</th>
                        <th>In ERP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><strong>{{ $row['name'] ?? 'The dish' }}</strong></td>
                            <td class="num">{{ $portions($row['portions']) }}</td>
                            <td class="num">{{ $cost($row['cost']) }}</td>
                            <td class="num">{{ $percent($row['percent']) }}</td>
                            <td>
                                <span class="badge {{ $row['on_sale'] ? 'badge-success' : 'badge-danger' }}">
                                    {{ $row['on_sale'] ? 'On sale' : 'Off sale' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="text-xs text-muted" style="margin:8px 0 0">
        Food cost is what a portion costs as a share of its price. Off sale means ERP's stock no longer
        covers a portion.
    </p>
@endif
