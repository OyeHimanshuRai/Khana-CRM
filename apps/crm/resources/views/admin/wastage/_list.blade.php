{{-- Swappable fragment: the log plus its pagination. --}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>When</th>
                <th>Item</th>
                <th style="text-align:right">Quantity</th>
                <th>Why</th>
                <th style="text-align:right">Value</th>
                <th>Recorded by</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td class="text-sm">
                        {{ $entry->wasted_at?->format('d M, g:i a') }}
                    </td>

                    <td>
                        <strong>{{ $entry->product?->name ?? '—' }}</strong>
                        @if ($entry->note)
                            <span class="text-xs text-muted" style="display:block">{{ $entry->note }}</span>
                        @endif
                    </td>

                    <td style="text-align:right" class="text-sm">{{ $entry->label() }}</td>

                    <td>
                        {{--
                            A staff meal is not a loss, and colouring it like
                            one would have somebody chasing a number that is
                            working as intended.
                        --}}
                        <span class="badge {{ $entry->isLoss() ? 'badge-danger' : '' }}">
                            {{ $entry->reasonLabel() }}
                        </span>
                    </td>

                    <td style="text-align:right">
                        <strong>{{ $money($entry->cost_value) }}</strong>
                    </td>

                    <td class="text-sm">{{ $entry->recorded_by_name ?? '—' }}</td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('inventory.wastage.delete')
                                <form method="POST" action="{{ route('admin.wastage.destroy', $entry) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Put {{ $entry->label() }} of {{ $entry->product?->name }} back on the shelf?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon"
                                            title="Put it back — recorded in error"
                                            aria-label="Reverse this entry">
                                        <x-icon name="trend-up" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="trash" :size="28" />
                            <h3>Nothing written off in this period</h3>
                            <p class="text-sm">
                                That is either very good news or nobody is recording it.
                                Unrecorded waste is indistinguishable from theft and from
                                over-portioning — once it is written down, those become
                                three different conversations.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>

        @if ($entries->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="4">On this page</th>
                    <th style="text-align:right">{{ $money($entries->sum('cost_value')) }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

<x-pagination :paginator="$entries" :per-page="$perPage" :page-sizes="$pageSizes" label="entries" />
