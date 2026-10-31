{{--
    Swappable fragment: who holds points.

    The balance comes from the grouped ledger sum the controller passes in —
    not from a column on the customer, and not from a query per row. See
    LoyaltyService for why there is no column, and the controller for why it
    is one query rather than a thousand.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Mobile</th>
                <th>Points</th>
                <th>Worth</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($members as $member)
                @php $points = (int) ($balances[$member->id] ?? 0); @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.loyalty.show', $member) }}"
                               data-modal="{{ route('admin.loyalty.show', $member) }}"
                               data-modal-title="{{ $member->name }}"
                               data-modal-sub="Points passbook"
                               data-modal-size="lg">{{ $member->name }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">{{ $member->code }}</span>
                    </td>

                    <td class="text-sm">{{ $member->mobile ?: '—' }}</td>

                    <td class="text-sm"><strong>{{ number_format($points) }}</strong></td>

                    <td class="text-sm">
                        @if ($program)
                            {{ number_format($program->valueOf($points), 2) }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.loyalty.show', $member) }}"
                               data-modal="{{ route('admin.loyalty.show', $member) }}"
                               data-modal-title="{{ $member->name }}"
                               data-modal-sub="Points passbook"
                               data-modal-size="lg"
                               aria-label="Passbook for {{ $member->name }}">
                                <x-icon name="search" :size="15" />
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <div class="empty">
                            <x-icon name="star" :size="28" />
                            <h3>Nobody holds points yet</h3>
                            <p class="text-sm">
                                Points are earned when a bill is settled against a named customer.
                                A walk-in with no record earns nothing, which is the usual case.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$members" :per-page="$perPage" :page-sizes="$pageSizes" label="members" />
