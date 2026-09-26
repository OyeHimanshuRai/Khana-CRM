@extends('admin.layouts.app')

@section('title', 'API Tokens')

@section('content')
    <x-page-header
        title="API Tokens"
        subtitle="For a captain's app, or a delivery aggregator"
        :crumbs="['Settings' => null, 'API Tokens' => null]"
    />

    <div class="card" style="padding:18px; margin-bottom:14px">
        <div class="form-section-title">What a token can reach</div>

        <p class="text-sm text-muted">
            The API lives at <code>{{ url('api/v1') }}</code>. Send the token as
            <code>Authorization: Bearer &lt;token&gt;</code>. Start with
            <code>GET {{ url('api/v1/me') }}</code> — it answers with the branch and the abilities
            the token holds, which is the quickest way to know a token works before debugging
            anything else.
        </p>

        <p class="text-sm text-muted">
            A token can only do what its abilities allow, and it works on the branch of whoever
            created it. Prices always come from the menu, never from the caller — an integration
            cannot name its own price.
        </p>
    </div>

    @allows('settings.api_tokens.create')
        <div class="card" style="padding:18px; margin-bottom:14px">
            <div class="form-section-title">New token</div>

            {{--
                data-token-form is read by api-tokens.js, which shows the plain
                token once. Sanctum stores a hash, so there is no second
                chance and no "show token" button — which the copy below says
                out loud, because somebody who closes this without copying will
                otherwise go looking for one.
            --}}
            <form method="POST" action="{{ route('admin.api-tokens.store') }}"
                  data-ajax data-token-form>
                @csrf

                <div class="settings-grid">
                    <div class="field">
                        <label for="tk-name">What is it for</label>
                        <input id="tk-name" type="text" name="name" class="form-control" required
                               maxlength="120" aria-invalid="false" placeholder="Swiggy integration">
                    </div>

                    <div class="field">
                        <label for="tk-expiry">Expires after</label>
                        <input id="tk-expiry" type="number" name="expires_in_days" class="form-control"
                               min="1" max="3650" aria-invalid="false" placeholder="Never">
                        <div class="form-hint">Days. Empty means it never expires.</div>
                    </div>
                </div>

                <div class="form-section-title" style="margin-top:14px">What it may do</div>

                <div class="settings-grid">
                    @foreach ($abilities as $key => $label)
                        <div class="field">
                            <label class="check">
                                <input type="checkbox" name="abilities[]" value="{{ $key }}">
                                <span>
                                    {{ $label }}
                                    <span class="text-xs text-muted" style="display:block">{{ $key }}</span>
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <div style="margin-top:14px">
                    <button type="submit" class="btn btn-primary">Create token</button>
                </div>
            </form>

            {{-- Filled in by api-tokens.js when a token comes back. --}}
            <div class="alert alert-warning" style="margin-top:14px" data-token-result hidden>
                <strong>Copy this now — it will not be shown again.</strong>
                <code style="display:block; margin-top:8px; word-break:break-all" data-token-value></code>
            </div>
        </div>
    @endallows

    <div class="card">
        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Abilities</th>
                        <th>Last used</th>
                        <th>Expires</th>
                        <th class="col-action">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($tokens as $token)
                        <tr>
                            <td><strong>{{ $token->name }}</strong></td>

                            <td class="text-sm">
                                @foreach ($token->abilities ?? [] as $ability)
                                    <span class="badge badge-info">{{ $ability }}</span>
                                @endforeach
                            </td>

                            <td class="text-sm">
                                @if ($token->last_used_at)
                                    {{ $token->last_used_at->diffForHumans() }}
                                @else
                                    {{-- Worth saying: a token created and never
                                         used usually means the integrator never
                                         received it. --}}
                                    <span class="text-muted">Never used</span>
                                @endif
                            </td>

                            <td class="text-sm">
                                {{ $token->expires_at?->format('j M Y') ?? 'Never' }}
                            </td>

                            <td class="col-action">
                                @allows('settings.api_tokens.delete')
                                    <form method="POST" action="{{ route('admin.api-tokens.destroy', $token->id) }}"
                                          data-ajax
                                          onsubmit="return confirm('Revoke “{{ $token->name }}”? Anything using it stops working immediately.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger" aria-label="Revoke">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <x-icon name="lock" :size="28" />
                                    <h3>No tokens yet</h3>
                                    <p class="text-sm">
                                        Create one when you connect a captain's app or a delivery
                                        aggregator. Nothing can reach the API without one.
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
