@extends('admin.layouts.app')

@section('title', 'Notifications')

@section('content')
    <x-page-header
        title="Notifications"
        subtitle="Across every branch you can reach. What you see is filtered by what you are entitled to act on."
        :crumbs="['Notifications' => null]"
    >
        <x-slot:actions>
            @if ($unread > 0)
                <form method="POST" action="{{ route('admin.alerts.read-all') }}" data-ajax>
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm">
                        <x-icon name="user-check" :size="15" /> Mark all read ({{ number_format($unread) }})
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="f-type" class="sr-only">Kind</label>
                <select id="f-type" name="type">
                    <option value="">Every kind</option>
                    @foreach ($types as $key => $definition)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $definition['label'] }}</option>
                    @endforeach
                </select>

                <label class="check" style="margin-left:8px">
                    <input type="checkbox" name="unread" value="1" @checked($unreadOnly)>
                    Unread only
                </label>

                <button type="submit" class="btn btn-sm">Filter</button>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.alerts.index') }}">Reset</a>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th style="width:1%"></th>
                        <th>Notification</th>
                        <th>Branch</th>
                        <th>When</th>
                        <th class="col-action">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($alerts as $alert)
                        <tr style="{{ $alert->isRead() ? 'opacity:.62' : '' }}">
                            <td>
                                <span class="stat-icon is-{{ $alert->level === 'info' ? 'info' : $alert->level }}"
                                      style="width:30px;height:30px">
                                    <x-icon :name="$alert->icon()" :size="15" />
                                </span>
                            </td>

                            <td>
                                <strong>
                                    @if ($alert->link)
                                        <a href="{{ url($alert->link) }}">{{ $alert->title }}</a>
                                    @else
                                        {{ $alert->title }}
                                    @endif
                                </strong>
                                @if ($alert->body)
                                    <span class="text-xs text-muted" style="display:block">{{ $alert->body }}</span>
                                @endif
                                <span class="text-xs text-muted" style="display:block">{{ $alert->typeLabel() }}</span>
                            </td>

                            {{--
                                The branch, because this list deliberately spans
                                them. A manager covering three shops needs to
                                know which one is running out of stock without
                                switching into it to find out.
                            --}}
                            <td class="text-sm">{{ $alert->shop?->name ?? '—' }}</td>

                            <td class="text-sm text-muted">
                                {{ $alert->created_at?->diffForHumans() }}
                                <span class="text-xs" style="display:block">
                                    {{ $alert->created_at?->format('d M Y H:i') }}
                                </span>
                            </td>

                            <td class="col-action">
                                @unless ($alert->isRead())
                                    <form method="POST" action="{{ route('admin.alerts.read', $alert) }}" data-ajax>
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-ghost">Dismiss</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <x-icon name="bell" :size="28" />
                                    <h3>Nothing to report</h3>
                                    <p class="text-sm">
                                        No tills left open and nothing running low.
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
