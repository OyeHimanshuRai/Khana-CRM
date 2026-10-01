<?php

namespace App\Console\Commands;

use App\Models\Passport\Client;
use App\Support\MachineScopes;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Creates the OAuth clients CRM and ERP sign in and read each other with.
 *
 * Two per product:
 *   - a public sign-in client (authorization code + PKCE, no secret) - what a
 *     browser is redirected to;
 *   - a confidential machine client (client credentials) - what the product
 *     reads the snapshot, and the other product's API, as. Which scopes it
 *     may ask for is App\Support\MachineScopes::PRODUCT.
 *
 * Safe to run again, which is how an install picks up a new scope: a client
 * that already exists is kept, its scopes and redirect address are brought
 * up to date, and its secret - already sitting in the product's .env - is
 * left alone. A secret is shown once, when its client is made, and never
 * again. Run with --fresh to rotate: the old clients are revoked and new
 * ones minted.
 */
final class SeedClients extends Command
{
    protected $signature = 'khana:clients {--fresh : Revoke existing Khana clients and mint new ones}';

    protected $description = 'Create or update the OAuth clients CRM and ERP sign in and read each other with';

    public function handle(ClientRepository $clients): int
    {
        if ($this->option('fresh')) {
            Client::query()->where('name', 'like', 'Khana %')->update(['revoked' => true]);
            $this->warn('Existing Khana clients revoked.');
        }

        foreach (MachineScopes::PRODUCT as $product => $scopes) {
            $label = strtoupper($product);

            // From config, not env(): once the config is cached, env() is empty.
            $base = rtrim((string) config("khana.urls.{$product}"), '/');

            if ($base === '') {
                $this->warn("Skipping {$label}: KHANA_{$label}_URL is not set.");

                continue;
            }

            $signIn = $this->existing("Khana {$label} sign-in") ?? $clients->createAuthorizationCodeGrantClient(
                name: "Khana {$label} sign-in",
                redirectUris: [$base.'/sso/callback'],
                confidential: false,
            );

            // A person's token carries no scopes at all: said here by name,
            // so nobody signing in through a browser can ask for a machine's.
            $signIn->forceFill(['redirect_uris' => [$base.'/sso/callback'], 'scopes' => []])->save();

            $machine = $this->existing("Khana {$label} machine");
            $secret = null;

            if ($machine === null) {
                $machine = $clients->createClientCredentialsGrantClient("Khana {$label} machine");
                $secret = $machine->plainSecret;
            }

            $machine->forceFill(['scopes' => $scopes])->save();

            $this->newLine();
            $this->info("── {$label} ".str_repeat('─', 40));
            $this->line('May ask for: '.implode(', ', $scopes));
            $this->line('');

            if ($secret === null) {
                $this->line('These clients already existed and were kept; their scopes are now up to date.');
                $this->line('apps/'.$product.'/.env stays as it is:');
            } else {
                $this->line('Paste into apps/'.$product.'/.env:');
            }

            $this->line('');
            $this->line('KHANA_SSO_CLIENT_ID='.$signIn->getKey());
            $this->line('KHANA_MACHINE_CLIENT_ID='.$machine->getKey());
            $this->line('KHANA_MACHINE_CLIENT_SECRET='.($secret ?? '(unchanged - shown only when the client was made)'));
        }

        $this->newLine();
        $this->info('Done. Each product also needs KHANA_ACCOUNTS_URL and KHANA_ACCOUNTS_PUBLIC_KEY (this app\'s storage/oauth-public.key).');

        return self::SUCCESS;
    }

    /** The client of this name that is still in use, if there is one. */
    private function existing(string $name): ?Client
    {
        return Client::query()->where('name', $name)->where('revoked', false)->latest()->first();
    }
}
