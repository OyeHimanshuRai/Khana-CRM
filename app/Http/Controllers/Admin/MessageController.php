<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Direct messages. Every conversation is looked up through the signed-in
 * user's own threads, so somebody else's id answers 404 rather than 403 and
 * does not confirm that the thread exists.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(Request $request): View
    {
        return view('admin.messages.index', ['me' => $request->user()]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $me = $request->user();

        $threads = Conversation::query()->involving($me)
            ->with(['userOne:id,name', 'userTwo:id,name'])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(100)->get();

        $ids = $threads->pluck('id');

        // One query for the newest message of every thread, one for unread counts.
        $latest = Message::query()
            ->whereIn('id', Message::query()->whereIn('conversation_id', $ids)
                ->select(DB::raw('MAX(id)'))->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');

        $unread = Message::query()->whereIn('conversation_id', $ids)
            ->where('sender_id', '!=', $me->id)->whereNull('read_at')
            ->selectRaw('conversation_id, COUNT(*) as c')->groupBy('conversation_id')
            ->pluck('c', 'conversation_id');

        return ApiResponse::success('OK', [
            'unread_total' => (int) $unread->sum(),
            'conversations' => $threads->map(fn (Conversation $c) => [
                'id' => $c->id,
                'with' => ($c->user_one_id === $me->id ? $c->userTwo : $c->userOne)?->name ?? 'Former user',
                'preview' => isset($latest[$c->id]) ? mb_strimwidth($latest[$c->id]->plainBody(), 0, 60, '…') : '',
                'unread' => (int) ($unread[$c->id] ?? 0),
                'at' => $c->last_message_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        $people = $this->messages->contacts($request->user())
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->limit(30)->get(['id', 'name']);

        return ApiResponse::success('OK', ['contacts' => $people]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);

        $other = User::query()->withoutGlobalScopes()->find($data['user_id']);

        // Same answer for "no such person" and "not allowed", so ids cannot be probed.
        if ($other === null || ! $this->messages->canMessage($request->user(), $other)) {
            return ApiResponse::error('You cannot message that person.', [], 404);
        }

        $conversation = $this->messages->open($request->user(), $other);

        return ApiResponse::success('OK', ['id' => $conversation->id, 'with' => $other->name]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $thread = $this->mine($me, $conversation);

        $after = max(0, (int) $request->query('after', 0));

        $rows = Message::query()->where('conversation_id', $thread->id)
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')->limit(100)->get()->reverse()->values();

        $this->messages->markRead($thread, $me);

        return ApiResponse::success('OK', [
            'messages' => $rows->map->toPayload(),
            'peer_read_up_to' => (int) Message::query()->where('conversation_id', $thread->id)
                ->where('sender_id', $me->id)->whereNotNull('read_at')->max('id'),
        ]);
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $thread = $this->mine($me, $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.MessageService::MAX_LENGTH],
            'client_uuid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        if (trim($data['body']) === '') {
            return ApiResponse::error('Write something first.', ['body' => ['Write something first.']]);
        }

        // Re-checked on every send: a person suspended or deactivated since the
        // thread began can no longer be written to, and cannot write.
        $other = User::query()->withoutGlobalScopes()->find($thread->otherId($me));

        if ($other === null || ! $this->messages->canMessage($me, $other)) {
            return ApiResponse::error('This person can no longer receive messages.', [], 403);
        }

        $message = $this->messages->send($thread, $me, $data['body'], $data['client_uuid']);

        return ApiResponse::success('Sent.', ['message' => $message->toPayload()], null, 201);
    }

    public function unread(Request $request): JsonResponse
    {
        return ApiResponse::success('OK', ['unread' => $this->messages->unreadCount($request->user())]);
    }

    private function mine(User $me, int $id): Conversation
    {
        return Conversation::query()->involving($me)->whereKey($id)->firstOrFail();
    }
}
