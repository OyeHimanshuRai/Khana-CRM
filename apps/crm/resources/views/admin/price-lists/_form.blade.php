@extends('admin.layouts.app')

@section('title', $list->exists ? 'Edit Price List' : 'New Price List')

@section('content')
    @php
        $isNew = ! $list->exists;
        $days = $list->weekdays ?? [];
        $time = fn ($value) => $value === null ? '' : substr((string) $value, 0, 5);
    @endphp

    <x-page-header
        :title="$isNew ? 'New Price List' : $list->name"
        subtitle="Prices that apply only sometimes"
        :crumbs="['Menu & Stock' => null, 'Price Lists' => route('admin.price-lists.index'), ($isNew ? 'New' : 'Edit') => null]"
    />

    {{--
        A full page rather than a modal, because a list has a variable number
        of dish rows and a modal that grows past the viewport is worse than a
        page that scrolls.
    --}}
    <form method="POST"
          action="{{ $isNew ? route('admin.price-lists.store') : route('admin.price-lists.update', $list) }}"
          data-ajax data-redirect="{{ route('admin.price-lists.index') }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">What it is</div>

            <div class="settings-grid">
                <div class="field">
                    <label for="pl-name">Name</label>
                    <input id="pl-name" type="text" name="name" class="form-control" required
                           value="{{ $list->name }}" maxlength="90" aria-invalid="false"
                           placeholder="Happy hour">
                </div>

                <div class="field">
                    <label for="pl-code">Code</label>
                    <input id="pl-code" type="text" name="code" class="form-control" required
                           value="{{ $list->code }}" maxlength="40" aria-invalid="false"
                           placeholder="happy-hour">
                </div>

                <div class="field">
                    <label for="pl-priority">Priority</label>
                    <input id="pl-priority" type="number" name="priority" class="form-control"
                           value="{{ $list->priority ?? 0 }}" min="0" max="1000" aria-invalid="false">
                    <div class="form-hint">
                        Highest wins when two lists cover the same dish at the same moment — which is
                        not an error, so somebody has to say which one the guest gets.
                    </div>
                </div>

                <div class="field">
                    <label class="check" style="margin-top:22px">
                        <input type="checkbox" name="is_active" value="1"
                               @checked($isNew ? true : $list->is_active)>
                        <span>On</span>
                    </label>
                    <div class="form-hint">Switching it off stops it immediately.</div>
                </div>
            </div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">When it runs</div>

            <p class="text-sm text-muted">
                Every part below is optional and narrows independently. Leave them all empty and the
                list simply always applies, which is how you express a permanent second price list.
            </p>

            <div class="settings-grid">
                <div class="field">
                    <label for="pl-from-time">From</label>
                    <input id="pl-from-time" type="time" name="starts_at" class="form-control"
                           value="{{ $time($list->starts_at) }}" aria-invalid="false">
                </div>

                <div class="field">
                    <label for="pl-to-time">Until</label>
                    <input id="pl-to-time" type="time" name="ends_at" class="form-control"
                           value="{{ $time($list->ends_at) }}" aria-invalid="false">
                    <div class="form-hint">
                        A window may cross midnight — 22:00 to 02:00 works and means what it says.
                    </div>
                </div>

                <div class="field">
                    <label for="pl-from-date">Starts on</label>
                    <input id="pl-from-date" type="date" name="starts_on" class="form-control"
                           value="{{ $list->starts_on?->toDateString() }}" aria-invalid="false">
                </div>

                <div class="field">
                    <label for="pl-to-date">Ends on</label>
                    <input id="pl-to-date" type="date" name="ends_on" class="form-control"
                           value="{{ $list->ends_on?->toDateString() }}" aria-invalid="false">
                </div>

                <div class="field">
                    <label for="pl-channel">Only for</label>
                    <select id="pl-channel" name="channel" class="form-control" aria-invalid="false">
                        <option value="">Every channel</option>
                        <option value="dine_in" @selected($list->channel === 'dine_in')>Dine-in</option>
                        <option value="takeaway" @selected($list->channel === 'takeaway')>Takeaway</option>
                        <option value="delivery" @selected($list->channel === 'delivery')>Delivery</option>
                        <option value="online" @selected($list->channel === 'online')>Online</option>
                    </select>
                </div>
            </div>

            <div class="form-section-title" style="margin-top:14px">Days</div>

            <div class="settings-grid">
                @foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                           5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $number => $day)
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" name="weekdays[]" value="{{ $number }}"
                                   @checked(in_array($number, $days, true))>
                            <span>{{ $day }}</span>
                        </label>
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-muted" style="margin-top:8px">
                None ticked means every day.
            </p>
        </div>

        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">What it changes</div>

            <p class="text-sm text-muted">
                A flat price or a percentage off, not both — a row with both stored would mean two
                answers for one number. Leave a row blank and it is ignored.
            </p>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Dish</th>
                            <th style="width:160px">Flat price</th>
                            <th style="width:160px">or % off</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $index => $product)
                            @php $row = $rows->firstWhere('product_id', $product->id); @endphp
                            <tr>
                                <td class="text-sm">
                                    {{ $product->name }}
                                    <span class="text-xs text-muted" style="display:block">
                                        normally {{ number_format((float) $product->selling_price, 2) }}
                                    </span>
                                    <input type="hidden" name="items[{{ $index }}][product_id]"
                                           value="{{ $product->id }}">
                                </td>
                                <td>
                                    <input type="number" class="form-control" step="0.01" min="0"
                                           name="items[{{ $index }}][price]"
                                           value="{{ $row?->price }}" placeholder="—">
                                </td>
                                <td>
                                    <input type="number" class="form-control" step="0.01" min="0" max="100"
                                           name="items[{{ $index }}][discount_percent]"
                                           value="{{ $row?->discount_percent }}" placeholder="—">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex; gap:8px">
            <a class="btn" href="{{ route('admin.price-lists.index') }}">Cancel</a>
            <button type="submit" class="btn btn-primary">
                {{ $isNew ? 'Create price list' : 'Save changes' }}
            </button>
        </div>
    </form>
@endsection
