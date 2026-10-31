<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Direct messages between two accounts.
 *
 * Who may talk to whom: people of the same company, and the platform's Super
 * Admin with anybody. Nobody else, so one restaurant cannot message another.
 * There is no moderator read-access: an administrator who is not a
 * participant gets a 404 like anyone else.
 */
class MessageService
{
    public const MAX_LENGTH = 2000;

    public function canMessage(User $from, User $to): bool
    {
        if ($from->id === $to->id || ! $to->is_active || ! $from->is_active) {
            return false;
        }

        if ($from->isSuperAdmin() || $to->isSuperAdmin()) {
            return true;
        }

        return $from->tenant_id !== null && $from->tenant_id === $to->tenant_id;
    }

    /** @return Builder<User> */
    public function contacts(User $me): Builder
    {
        return User::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereKeyNot($me->id)
            ->when(! $me->isSuperAdmin(), fn (Builder $q) => $q->where(
                fn ($w) => $w->where('tenant_id', $me->tenant_id)
                    ->orWhereHas('roles', fn ($r) => $r->where('name', User::SUPER_ADMIN))
            ))
            ->orderBy('name');
    }

    public function open(User $me, User $other): Conversation
    {
        [$a, $b] = $me->id < $other->id ? [$me->id, $other->id] : [$other->id, $me->id];

        return Conversation::query()->firstOrCreate(['user_one_id' => $a, 'user_two_id' => $b]);
    }

    /**
     * Save a message once, however many times the browser retries it.
     */
    public function send(Conversation $conversation, User $sender, string $body, string $clientUuid): Message
    {
        $body = trim($body);

        return DB::transaction(function () use ($conversation, $sender, $body, $clientUuid) {
            $existing = $this->byUuid($conversation, $clientUuid);

            if ($existing !== null) {
                return $existing;
            }

            try {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'body' => $body,
                    'client_uuid' => $clientUuid,
                ]);
            } catch (QueryException) {
                // Two identical retries raced; the other one won.
                return $this->byUuid($conversation, $clientUuid) ?? throw new \RuntimeException('Message could not be saved.');
            }

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });
    }

    private function byUuid(Conversation $conversation, string $uuid): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('client_uuid', $uuid)
            ->first();
    }

    /** Mark everything the other person sent as read. */
    public function markRead(Conversation $conversation, User $me): int
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(User $me): int
    {
        return Message::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $me->id)
            ->whereIn('conversation_id', Conversation::query()->involving($me)->select('id'))
            ->count();
    }
}
