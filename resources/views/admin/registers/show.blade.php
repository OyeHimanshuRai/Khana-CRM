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
                <div><dt>Opened at</dt><dd>{{ $register->opened_at?->format('d M Y, H:i') ?? '—' }}</dd></div>

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
                    <div><dt>Closed at</dt><dd>{{ $register->closed_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
                @endif

                @if ($register->isApproved())
                    <div><dt>Approved by</dt><dd>{{ $register->approved_by_name ?? '—' }}</dd></div>
                    <div><dt>Approved at</dt><dd>{{ $register->approved_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
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

    @if ($register->isOpen())
        @allows('pos.registers.edit')
            <div class="card" style="margin-top:16px">
                <div class="card-header">
                    <div class="card-title">Close the register</div>
                    <div class="text-xs text-muted">
                        Count the drawer and enter what is actually there. The expected figure above updates
                        live from today's cash payments and approved cash expenses.
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
