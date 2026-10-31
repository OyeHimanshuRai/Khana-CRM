<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * One message. The body is encrypted with the application key before it
 * reaches the database. That protects a leaked dump or backup; it is not
 * end-to-end encryption, because the server holds the key and decrypts for
 * the two participants.
 */
class Message extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'body', 'client_uuid', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function setBodyAttribute(string $value): void
    {
        $this->attributes['body'] = Crypt::encryptString($value);
    }

    public function plainBody(): string
    {
        try {
            return Crypt::decryptString($this->attributes['body']);
        } catch (DecryptException) {
            // An app key that changed since this was written.
            return '[message cannot be decrypted]';
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'body' => $this->plainBody(),
            'client_uuid' => $this->client_uuid,
            'read' => $this->read_at !== null,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
