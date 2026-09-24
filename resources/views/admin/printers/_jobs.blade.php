{{--
    Swappable fragment: the print log.

    Failures are kept and shown with their reason. That is the whole value of
    this screen on the evening it matters: "the tandoor never got the ticket"
    is a question the log can answer, and a job that quietly vanished cannot.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>When</th>
                <th>What</th>
                <th>Printer</th>
                <th>Who</th>
                <th>Status</th>
                <th>Why</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td class="text-sm">
                        {{ $job->created_at?->format('j M, g:i a') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $job->created_at?->diffForHumans() }}
                        </span>
                    </td>

                    <td class="text-sm">
                        <strong>{{ $job->subjectLabel() }}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ ucfirst($job->kind) }}@if ($job->station) · {{ $job->station->name }} @endif
                            @if ($job->copies > 1) · {{ $job->copies }} copies @endif
                        </span>
                    </td>

                    <td class="text-sm">
                        {{ $job->printer?->name ?? 'Browser' }}
                    </td>

                    <td class="text-sm">{{ $job->user?->name ?? '—' }}</td>

                    <td>
                        <span class="badge badge-{{ $job->statusTone() }}">
                            <span class="badge-dot"></span> {{ $job->statusLabel() }}
                        </span>
                        @if ($job->is_reprint)
                            <span class="badge badge-warning" style="margin-left:4px">Reprint</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($job->error)
                            <span class="text-danger">{{ $job->error }}</span>
                        @elseif ($job->reason)
                            {{ $job->reason }}
                        @elseif ($job->status === App\Models\PrintJob::QUEUED)
                            <span class="text-muted">Browser print — nobody can tell whether it came out</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>Nothing printed yet</h3>
                            <p class="text-sm">Kitchen tickets and bills will appear here as they go out.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$jobs" :per-page="$perPage" :page-sizes="$pageSizes" label="print jobs" />
