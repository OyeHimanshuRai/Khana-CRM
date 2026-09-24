{{--
    The bell dropdown.

    A fragment injected into the header, so no <script> may live here — anything
    interactive is delegated from a globally loaded file. Deliberately short:
    the full list is its own page, and a dropdown that scrolls is a dropdown
    nobody reads.
--}}

<div class="alert-drop">
    <div class="alert-drop-head">
        <span class="card-title">Notifications</span>

        @if ($unread > 0)
            <form method="POST" action="{{ route('admin.alerts.read-all') }}" data-ajax>
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-sm btn-ghost">Mark all read</button>
            </form>
        @endif
    </div>

    <ul class="alert-list">
        @forelse ($alerts as $alert)
            <li class="alert-item {{ $alert->isRead() ? 'is-read' : '' }}">
                <span class="stat-icon is-{{ $alert->level === 'info' ? 'info' : $alert->level }}"
                      style="width:28px;height:28px;flex:none">
                    <x-icon :name="$alert->icon()" :size="14" />
                </span>

                <div class="alert-body">
                    @if ($alert->link)
                        <a href="{{ url($alert->link) }}" class="alert-title">{{ $alert->title }}</a>
                    @else
                        <span class="alert-title">{{ $alert->title }}</span>
                    @endif

                    @if ($alert->body)
                        <span class="text-xs text-muted" style="display:block">{{ $alert->body }}</span>
                    @endif

                    <span class="text-xs text-muted" style="display:block">
                        {{ $alert->shop?->name ? $alert->shop->name.' · ' : '' }}{{ $alert->created_at?->diffForHumans() }}
                    </span>
                </div>
            </li>
        @empty
            <li class="alert-item">
                <div class="alert-body">
                    <span class="text-sm text-muted">Nothing to report.</span>
                </div>
            </li>
        @endforelse
    </ul>

    <div class="alert-drop-foot">
        <a href="{{ route('admin.alerts.index') }}">See all notifications</a>
    </div>
</div>
