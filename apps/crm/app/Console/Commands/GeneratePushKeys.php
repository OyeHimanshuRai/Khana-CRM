<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Generate the VAPID pair web push needs (SRS 16).
 *
 * Printed rather than written to .env. Writing to an environment file from a
 * command is the sort of convenience that eventually overwrites a production
 * key somebody spent an afternoon rotating, and the two lines below are a
 * copy and a paste.
 */
class GeneratePushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate a VAPID key pair for web push';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('Could not generate keys: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Add these to your .env, then set PUSH_ENABLED=true:');
        $this->newLine();
        $this->line('PUSH_PUBLIC_KEY="'.$keys['publicKey'].'"');
        $this->line('PUSH_PRIVATE_KEY="'.$keys['privateKey'].'"');
        $this->newLine();

        /*
         | Said out loud because it is the one mistake that matters here: the
         | public key is sent to every browser by design, and the private one
         | signs. Swapping them silently breaks every subscription.
         */
        $this->warn('The public key goes to browsers. The private key never leaves the server.');

        return self::SUCCESS;
    }
}
