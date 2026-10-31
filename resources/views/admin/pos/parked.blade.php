@extends('admin.layouts.app')

@section('title', 'Held Sales')

@section('content')
    <x-page-header
        title="Held Sales"
        subtitle="Baskets put to one side"
        :crumbs="['Counter' => null, 'Held Sales' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.pos.terminal') }}">
                <x-icon name="cart" :size="15" /> Back to the till
            </a>
        </x-slot:actions>
    </x-page-header>

    {{--
        A held sale is not an invoice. Nothing here is numbered, no stock has
        moved and no ledger line exists — which is why this screen can throw
        one away without any of the ceremony a real document would need.
    --}}
    <div class="card">
        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Named</th>
                        <th>Lines</th>
                        <th>Total</th>
                        <th>Held by</th>
                        <th>When</th>
                        <th class="col-action">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($sales as $sale)
                        <tr @if ($sale->isStale()) style="background: var(--warning-soft)" @endif>
                            <td><span class="list-ref">{{ $sale->reference }}</span></td>

                            <td class="text-sm">
                                <strong>{{ $sale->displayLabel() }}</strong>
                                @if ($sale->customer)
                                    <span class="text-xs text-muted" style="display:block">
                                        {{ $sale->customer->name }}
                                    </span>
                                @endif
                            </td>

                            <td class="text-sm">{{ $sale->line_count }}</td>

                            <td class="text-sm">₹{{ number_format((float) $sale->total, 2) }}</td>

                            <td class="text-sm">{{ $sale->user?->name ?? '—' }}</td>

                            <td class="text-sm">
                                {{ $sale->created_at?->format('g:i a') }}
                                @if ($sale->isStale())
                                    <span class="text-xs" style="display:block; color: var(--warning)">
                                        {{ $sale->created_at->diffForHumans() }} — still here?
                                    </span>
                                @endif
                            </td>

                            <td class="col-action">
                                <div class="row-actions">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            data-pos-resume
                                            data-resume-url="{{ route('admin.pos.resume', $sale) }}"
                                            data-terminal-url="{{ route('admin.pos.terminal') }}">
                                        Resume
                                    </button>

                                    <form method="POST" action="{{ route('admin.pos.discard', $sale) }}"
                                          data-ajax
                                          onsubmit="return confirm('Throw away {{ $sale->reference }}? Nothing was billed, so nothing is reversed — the basket is simply gone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Discard {{ $sale->reference }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty">
                                    <x-icon name="inbox" :size="28" />
                                    <h3>Nothing is being held</h3>
                                    <p class="text-sm">
                                        Use <strong>Hold</strong> at the till to put a basket down and come
                                        back to it — a customer who went to fetch one more thing, or a card
                                        that would not read.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    {{--
        Resume lives in pos.js, and this is the screen its button is on.

        Without this the button rendered, looked live, and did nothing at all
        when pressed - no navigation, no error, nothing in the console -
        because the delegated click handler that claims the sale had never
        been loaded on this page. A held basket was a one-way trip.
    --}}
    <script src="{{ asset('assets/js/pos.js') }}?v={{ filemtime(public_path('assets/js/pos.js')) }}"></script>
@endpush
