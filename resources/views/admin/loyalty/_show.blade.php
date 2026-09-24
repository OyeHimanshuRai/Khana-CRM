{{--
    A customer's passbook, and the one action that writes to it.

    The balance after each line is shown so "why do I have 340 points" has an
    answer somebody can point at rather than assert.
--}}

<div class="sec-name">{{ $customer->name }}</div>
<div class="text-sm text-muted">{{ $customer->mobile ?: $customer->code }}</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-info">{{ number_format($balance) }} points</span>
    @if ($program)
        <span class="badge badge-muted">worth {{ number_format($program->valueOf($balance), 2) }}</span>
    @endif
</div>

@allows('crm.loyalty.adjust')
    <div class="form-section">
        <div class="form-section-title">Adjust by hand</div>

        <form method="POST" action="{{ route('admin.loyalty.adjust', $customer) }}"
              data-ajax data-close-modal data-refresh-list>
            @csrf

            <div class="settings-grid">
                <div class="field">
                    <label for="adj-points">Points</label>
                    <input id="adj-points" type="number" name="points" class="form-control" required
                           aria-invalid="false" placeholder="250 or -250">
                    <div class="form-hint">Negative takes points away.</div>
                </div>

                <div class="field">
                    <label for="adj-note">Why</label>
                    <input id="adj-note" type="text" name="note" class="form-control" required
                           maxlength="190" aria-invalid="false"
                           placeholder="Goodwill — cold soup on 12 Sept">
                    <div class="form-hint">
                        Required. An adjustment nobody explained is the one line on a statement
                        that cannot be defended later.
                    </div>
                </div>
            </div>

            <div style="margin-top:10px">
                <button type="submit" class="btn btn-primary">Adjust</button>
            </div>
        </form>
    </div>
@endallows

<div class="form-section">
    <div class="form-section-title">Passbook</div>

    @if ($statement->isEmpty())
        <p class="text-sm text-muted">Nothing yet.</p>
    @else
        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>What</th>
                        <th>Points</th>
                        <th>Balance</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($statement as $row)
                        <tr>
                            <td class="text-sm">{{ $row->created_at?->format('j M Y') }}</td>
                            <td>
                                <span class="badge badge-{{ $row->typeTone() }}">{{ $row->typeLabel() }}</span>
                            </td>
                            <td class="text-sm">
                                <strong>{{ $row->points > 0 ? '+' : '' }}{{ number_format($row->points) }}</strong>
                            </td>
                            <td class="text-sm">{{ number_format($row->balance_after) }}</td>
                            <td class="text-sm">
                                {{ $row->note ?: '—' }}
                                @if ($row->expires_at)
                                    <span class="text-xs text-muted" style="display:block">
                                        lapses {{ $row->expires_at->format('j M Y') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>
</div>
