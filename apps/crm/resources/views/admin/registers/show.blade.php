@extends('admin.layouts.app')

@section('title', 'Register — '.$register->business_date->format('d M Y'))

@section('content')
    <x-page-header
        :title="$register->business_date->format('d M Y')"
        :subtitle="$register->shop?->name"
        :crumbs="['POS' => null, 'Day Close' => route('admin.registers.index'), $register->business_date->format('d M Y') => null]"
    >
        <x-slot:actions>
            <span class="badge {{ $register->statusTone() ? 'badge-'.$register->statusTone() : '' }}">
                <span class="badge-dot"></span> {{ $register->statusLabel() }}
            </span>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-body">
            <dl class="sec-facts">
                <div><dt>Opening float</dt><dd>₹{{ number_format((float) $register->opening_float, 2) }}</dd></div>
                <div><dt>Opened by</dt><dd>{{ $register->opened_by_name ?? '—' }}</dd></div>
                {{-- Times are stored in UTC and read here on the outlet's own
                     clock, the one the paid-out list below is on too. --}}
                <div><dt>Opened at</dt><dd>{{ $register->opened_at?->timezone($zone)->format('d M Y, H:i') ?? '—' }}</dd></div>

                @if ($register->isOpen())
                    <div>
                        <dt>Expected so far</dt>
                        <dd>₹{{ number_format($expectedSoFar, 2) }}</dd>
                    </div>
                @else
                    <div><dt>Expected</dt><dd>₹{{ number_format((float) $register->expected_cash, 2) }}</dd></div>
                    <div><dt>Counted</dt><dd>₹{{ number_format((float) $register->counted_cash, 2) }}</dd></div>
                    <div>
                        <dt>Variance</dt>
                        <dd>
                            @if ($register->isBalanced())
                                <span style="color:var(--success)">Balanced</span>
                            @else
                                <strong style="color:{{ $register->isShort() ? 'var(--danger)' : 'var(--warning)' }}">
                                    {{ $register->isOver() ? 'Over' : 'Short' }} by
                                    ₹{{ number_format(abs((float) $register->variance), 2) }}
                                </strong>
                            @endif
                        </dd>
                    </div>
                    <div><dt>Closed by</dt><dd>{{ $register->closed_by_name ?? '—' }}</dd></div>
                    <div><dt>Closed at</dt><dd>{{ $register->closed_at?->timezone($zone)->format('d M Y, H:i') ?? '—' }}</dd></div>
                @endif

                @if ($register->isApproved())
                    <div><dt>Approved by</dt><dd>{{ $register->approved_by_name ?? '—' }}</dd></div>
                    <div><dt>Approved at</dt><dd>{{ $register->approved_at?->timezone($zone)->format('d M Y, H:i') ?? '—' }}</dd></div>
                @endif
            </dl>

            @if ($register->notes)
                <div style="margin-top:16px">
                    <div class="form-label">Closing notes</div>
                    <p class="text-sm text-muted">{{ $register->notes }}</p>
                </div>
            @endif

            @if ($register->review_note)
                <div style="margin-top:14px">
                    <div class="form-label">Reviewer's note</div>
                    <p class="text-sm text-muted">{{ $register->review_note }}</p>
                </div>
            @endif
        </div>
    </div>

    {{--
        Cash out of the drawer.

        Only on the open register of the outlet's own day - see
        CashRegisterService::takesPaidOuts() for why a register left open
        from yesterday is not offered it.
    --}}
    @if ($takesPaidOuts)
        @allows('pos.registers.create')
            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div class="card-title">Pay cash out of the drawer</div>
                    <div class="text-xs text-muted">
                        For cash that leaves the till and is not a refund: a supplier paid at the door,
                        the gas, an auto fare. It comes off the expected figure straight away.
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.registers.paid-outs.store', $register) }}"
                          data-ajax data-redirect-delay="600" data-paid-out-form>
                        @csrf
                        <div class="settings-grid">
                            {{--
                                The bills ERP says are unpaid, fetched once the
                                page is up so a slow ERP holds up this line and
                                not the till. Not drawn at all for an outlet
                                with CRM alone: it has no bills to pick from.
                            --}}
                            @if ($erpLinked)
                                <div class="field-full"
                                     data-erp-panel="{{ route('admin.registers.supplier-bills', $register) }}">
                                    <div class="field">
                                        <div class="form-label">Supplier bill</div>
                                        <p class="text-sm text-muted" style="margin:0">Looking for unpaid bills…</p>
                                    </div>
                                </div>
                            @endif

                            <div class="field">
                                <label for="po-amount">Amount (₹)</label>
                                <input id="po-amount" type="number" step="0.01" min="0.01" name="amount"
                                       class="form-control" required aria-invalid="false" data-paid-out-amount>
                            </div>

                            <div class="field">
                                <label for="po-category">What it was for</label>
                                <select id="po-category" name="category" class="form-control" required
                                        aria-invalid="false" data-paid-out-category>
                                    <option value="">Choose…</option>
                                    @foreach ($categories as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="field field-full">
                                <label for="po-note">Note</label>
                                <input id="po-note" type="text" name="note" class="form-control" maxlength="190"
                                       required autocomplete="off" aria-invalid="false" data-paid-out-note
                                       placeholder="Two cylinders, or who was paid and for what.">
                                <div class="form-hint">Whoever closes the day reads this to see where the cash went.</div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="wallet" :size="15" /> Pay out
                        </button>
                    </form>
                </div>
            </div>
        @endallows
    @endif

    {{--
        What left the drawer that day: the same rows the expected figure has
        already taken off, so the figure can be checked rather than believed.
        Kept on a closed day when there is something in it - it is the first
        thing a manager signing off a short till wants to read.
    --}}
    @if ($register->isOpen() || $paidOuts->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Paid out of the drawer</div>
                    <div class="text-xs text-muted">
                        {{ $register->business_date->format('d M Y') }} · already taken off the expected figure
                    </div>
                </div>
            </div>

            @if ($paidOuts->isEmpty())
                <div class="card-body">
                    <p class="text-sm text-muted" style="margin:0">No cash has been paid out of the drawer.</p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>What for</th>
                                <th>Who</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($paidOuts as $paidOut)
                                @php $bill = $paidOut->billLabel(); @endphp
                                <tr>
                                    <td class="text-sm">{{ $paidOut->paid_at->timezone($zone)->format('H:i') }}</td>
                                    <td>
                                        <strong>{{ $paidOut->categoryLabel() }}</strong>
                                        @if ($bill)
                                            <span class="text-sm" style="display:block">{{ $bill }}</span>
                                        @endif
                                        {{-- A bill picked with no note is recorded
                                             under its own name; once is enough. --}}
                                        @if ($paidOut->reason !== $bill)
                                            <span class="text-sm text-muted" style="display:block">{{ $paidOut->reason }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">{{ $paidOut->paid_by_name ?? '—' }}</td>
                                    <td class="num">₹{{ number_format((float) $paidOut->amount, 2) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td colspan="3"><strong>Total paid out</strong></td>
                                <td class="num"><strong>₹{{ number_format($paidOutTotal, 2) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if ($register->isOpen())
        @allows('pos.registers.edit')
            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div class="card-title">Close the register</div>
                    <div class="text-xs text-muted">
                        Count the drawer and enter what is actually there. The expected figure above updates
                        live from today's cash payments and what has been paid out of the drawer.
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.registers.close', $register) }}"
                          data-ajax data-redirect-delay="600">
                        @csrf
                        @method('PUT')
                        <div class="settings-grid">
                            <div class="field">
                                <label for="reg-counted">Counted cash</label>
                                <input id="reg-counted" type="number" step="0.01" name="counted_cash"
                                       class="form-control" min="0" required aria-invalid="false">
                            </div>
                            <div class="field field-full">
                                <label for="reg-notes">Notes</label>
                                <textarea id="reg-notes" name="notes" class="form-control"
                                          style="min-height:56px" aria-invalid="false"
                                          placeholder="Denomination breakdown, or anything that explains a difference."></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Close register</button>
                    </form>
                </div>
            </div>
        @endallows
    @elseif ($register->isClosed())
        @allows('pos.registers.approve')
            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div class="card-title">Approve</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.registers.approve', $register) }}"
                          data-ajax data-redirect-delay="600">
                        @csrf
                        @method('PUT')
                        <div class="field">
                            <label for="reg-review">Review note</label>
                            <textarea id="reg-review" name="review_note" class="form-control"
                                      style="min-height:56px" aria-invalid="false"
                                      placeholder="Especially worth a line if the till did not balance."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Approve</button>
                    </form>
                </div>
            </div>
        @endallows
    @endif
@endsection
