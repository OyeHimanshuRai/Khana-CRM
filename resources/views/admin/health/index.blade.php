@extends('admin.layouts.app')

@section('title', 'System Health')

@section('content')
    {{--
        Is anything quietly broken? (§17)

        Everything on this page is a failure nobody is standing in front of:
        a job that threw at two in the morning, a backup that stopped running
        in March, a disk filling up. The screens that matter — the till, the
        kitchen board — will never mention any of it, which is the whole
        reason this one exists.
    --}}
    <x-page-header
        title="System Health"
        subtitle="Failed jobs, backups and errors — the things nothing else tells you about"
        :crumbs="['System' => null, 'Health' => null]"
    >
        <x-slot:actions>
            @allows('settings.health.create')
                <form method="POST" action="{{ route('admin.health.backup') }}" data-ajax
                      onsubmit="return confirm('Take a backup now? On a large database this takes a minute or two.')">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="download" :size="15" /> Back up now
                    </button>
                </form>
            @endallows

            <a class="btn btn-ghost" href="{{ route('admin.health.index') }}">
                <x-icon name="scan" :size="15" /> Refresh
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- ------------------------------------------------------- the summary -- --}}
    <div class="stat-grid">
        @php $queue = $snapshot['queue']; @endphp
        <div class="stat">
            <div class="stat-icon @if ($queue['tone'] === 'success') is-success @endif"
                 @if ($queue['tone'] === 'danger') style="background: var(--danger-soft); color: var(--danger)"
                 @elseif ($queue['tone'] === 'warning') style="background: var(--warning-soft); color: var(--warning)" @endif>
                <x-icon name="zap" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Failed jobs</div>
                <div class="stat-value">
                    {{ $queue['failed'] === null ? '—' : number_format($queue['failed']) }}
                </div>
                <span class="text-xs text-muted">
                    @if ($queue['failed'] === null)
                        no failed-job table on the {{ $queue['driver'] }} driver
                    @elseif ($queue['oldest_failed'])
                        oldest {{ $queue['oldest_failed']->diffForHumans() }}
                    @else
                        nothing has failed
                    @endif
                </span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Queued</div>
                <div class="stat-value">{{ $queue['pending'] === null ? '—' : number_format($queue['pending']) }}</div>
                <span class="text-xs text-muted">
                    @if ($queue['worker_suspect'])
                        {{-- The failure this number exists to catch. --}}
                        waiting — is <code>queue:work</code> running?
                    @else
                        on the {{ $queue['driver'] }} driver
                    @endif
                </span>
            </div>
        </div>

        @php $backups = $snapshot['backups']; @endphp
        <div class="stat">
            <div class="stat-icon @if ($backups['tone'] === 'success') is-success @endif"
                 @if ($backups['tone'] === 'danger') style="background: var(--danger-soft); color: var(--danger)"
                 @elseif ($backups['tone'] === 'warning') style="background: var(--warning-soft); color: var(--warning)" @endif>
                <x-icon name="shield" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Last backup</div>
                <div class="stat-value" style="font-size:18px">
                    {{ $backups['latest_at'] ? $backups['latest_at']->diffForHumans(short: true) : 'Never' }}
                </div>
                <span class="text-xs text-muted">
                    {{ $backups['count'] }} kept
                    @if (! empty($backups['total_size']))
                        · {{ round($backups['total_size'] / 1048576, 1) }} MB
                    @endif
                </span>
            </div>
        </div>

        @php $storage = $snapshot['storage']; @endphp
        <div class="stat">
            <div class="stat-icon @if ($storage['tone'] === 'success') is-success @endif"
                 @if ($storage['tone'] === 'danger') style="background: var(--danger-soft); color: var(--danger)"
                 @elseif ($storage['tone'] === 'warning') style="background: var(--warning-soft); color: var(--warning)" @endif>
                <x-icon name="package" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Disk used</div>
                <div class="stat-value">
                    {{ $storage['used_percent'] === null ? '—' : $storage['used_percent'].'%' }}
                </div>
                <span class="text-xs text-muted">
                    @if ($storage['free_bytes'] !== null)
                        {{ round($storage['free_bytes'] / 1073741824, 1) }} GB free
                    @else
                        could not read the disk
                    @endif
                </span>
            </div>
        </div>
    </div>

    @if (! empty($backups['note']))
        <div class="card" style="border-left:3px solid var(--{{ $backups['tone'] }})">
            <div class="card-body text-sm">{{ $backups['note'] }}</div>
        </div>
    @endif

    {{-- --------------------------------------------------- failed jobs -- --}}
    <div class="card">
        <div class="card-head">
            <h2 class="card-title">Failed jobs</h2>

            @if ($failedJobs->isNotEmpty())
                <div class="row-actions">
                    @allows('settings.health.edit')
                        <form method="POST" action="{{ route('admin.health.jobs.retry-all') }}" data-ajax
                              onsubmit="return confirm('Retry every failed job? Anything with a real-world effect — a payment webhook, a campaign send — will run again.')">
                            @csrf
                            <button type="submit" class="btn btn-sm">Retry all</button>
                        </form>
                    @endallows

                    @allows('settings.health.delete')
                        <form method="POST" action="{{ route('admin.health.jobs.discard') }}" data-ajax
                              onsubmit="return confirm('Discard every failed job? This destroys the only record of what went wrong.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm is-danger">Discard all</button>
                        </form>
                    @endallows
                </div>
            @endif
        </div>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>Failed</th>
                        <th>Job</th>
                        <th>Queue</th>
                        <th>Why</th>
                        <th class="col-action">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($failedJobs as $job)
                        <tr>
                            <td class="text-sm">
                                {{ $job->failed_at?->format('j M, g:i a') }}
                                <span class="text-xs text-muted" style="display:block">
                                    {{ $job->failed_at?->diffForHumans() }}
                                </span>
                            </td>
                            <td class="text-sm">{{ class_basename($job->job) }}</td>
                            <td class="text-sm">{{ $job->queue }}</td>
                            {{-- First line only; the trace stays in the log. --}}
                            <td class="text-sm">{{ Str::limit($job->reason, 90) }}</td>
                            <td class="col-action">
                                <div class="row-actions">
                                    @allows('settings.health.edit')
                                        <form method="POST" action="{{ route('admin.health.jobs.retry') }}" data-ajax>
                                            @csrf
                                            <input type="hidden" name="uuid" value="{{ $job->uuid }}">
                                            <button type="submit" class="btn btn-sm">Retry</button>
                                        </form>
                                    @endallows

                                    @allows('settings.health.delete')
                                        <form method="POST" action="{{ route('admin.health.jobs.discard') }}" data-ajax
                                              onsubmit="return confirm('Discard this job? There will be no record of it.')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="uuid" value="{{ $job->uuid }}">
                                            <button type="submit" class="btn btn-icon is-danger" aria-label="Discard">
                                                <x-icon name="trash" :size="15" />
                                            </button>
                                        </form>
                                    @endallows
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <x-icon name="shield" :size="28" />
                                    <h3>Nothing has failed</h3>
                                    <p class="text-sm">Every queued job has run. This is the state to expect.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- -------------------------------------------------- configuration -- --}}
    <div class="card">
        <div class="card-head"><h2 class="card-title">Configuration</h2></div>

        <div class="table-wrap">
            <table class="table table-list">
                <tbody>
                    @foreach ($snapshot['environment'] as $check)
                        <tr>
                            <td class="text-sm" style="width:180px">{{ $check['label'] }}</td>
                            <td style="width:120px">
                                <span class="badge badge-{{ $check['tone'] }}">{{ $check['value'] }}</span>
                            </td>
                            <td class="text-sm text-muted">{{ $check['note'] ?? '' }}</td>
                        </tr>
                    @endforeach

                    <tr>
                        <td class="text-sm">Writable paths</td>
                        <td>
                            <span class="badge badge-{{ $storage['all_writable'] ? 'success' : 'danger' }}">
                                {{ $storage['all_writable'] ? 'All writable' : 'Problem' }}
                            </span>
                        </td>
                        <td class="text-sm text-muted">
                            @php
                                $bad = collect($storage['writable'])->reject()->keys();
                            @endphp
                            @if ($bad->isEmpty())
                                logs, cache, sessions, uploads and backups
                            @else
                                {{-- Named, because "a path is not writable" is
                                     not an actionable sentence. --}}
                                Cannot write to: {{ $bad->implode(', ') }}
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ---------------------------------------------------- the error log -- --}}
    <div class="card">
        <div class="card-head">
            <h2 class="card-title">Recent errors</h2>
            <span class="text-xs text-muted">newest first, from the tail of laravel.log</span>
        </div>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th style="width:170px">When</th>
                        <th style="width:100px">Level</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($errors as $error)
                        <tr>
                            <td class="text-sm">{{ $error['at'] }}</td>
                            <td>
                                <span class="badge badge-{{ $error['level'] === 'ERROR' ? 'warning' : 'danger' }}">
                                    {{ $error['level'] }}
                                </span>
                            </td>
                            <td class="text-sm" style="overflow-wrap:anywhere">{{ $error['message'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <div class="empty">
                                    <x-icon name="file" :size="28" />
                                    <h3>No errors logged</h3>
                                    <p class="text-sm">
                                        Nothing at ERROR or above in the recent log. Lower levels are not
                                        shown here — a log full of INFO is how the one line that matters
                                        gets missed.
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
