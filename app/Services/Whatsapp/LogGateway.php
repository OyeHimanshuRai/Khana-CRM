<?php

namespace App\Services\Whatsapp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Write the message to the log instead of sending it.
 *
 * The default, and the same reasoning as the SMS log driver: a feature that
 * cannot be tried without a Meta Business account and an approved template
 * does not get tried, and therefore ships broken.
 *
 * The template name and its variables are logged as well as the body,
 * because the commonest WhatsApp mistake is a template that is not approved
 * under the name the code is using - and that is invisible if only the
 * rendered text is written down.
 */
class LogGateway implements WhatsAppGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Write to the log (nothing is sent)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $template, array $variables = [], ?string $body = null): bool
    {
        Log::channel((string) ($this->config['channel'] ?? 'stack'))->info(sprintf(
            'WhatsApp [%s] template=%s vars=[%s] body=%s',
            $to,
            $template,
            implode(' | ', $variables),
            $body ?? '',
        ));

        return true;
    }
}
