<?php

namespace App\Console\Commands;

use App\Models\Passport\Client;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Creates the OAuth clients CRM and ERP sign in and read the snapshot with.
 *
 * Two per product:
 *   - a public sign-in client (authorization code + PKCE, no secret) - what a
 *     browser is redirected to;
 *   - a confidential machine client (client credentials, scope tenants:read)
 *     - what the product reads the snapshot as.
 *
 * A secret is shown once, at creation, and never again. The command prints
 * the three lines each product needs in its own .env. Run again with --fresh
 * to rotate: the old clients are revoked and new ones minted.
 */
final class SeedClients extends Command
{
    protected $signature = 'khana:clients {--fresh : Revoke existing Khana clients and mint new ones}';

    protected $description = 'Create the OAuth clients CRM and ERP use to sign in and read the snapshot';

    /** product => the URL its sign-in comes back to. */
    private const PRODUCTS = [
        'crm' => 'KHANA_CRM_URL',
        'erp' => 'KHANA_ERP_URL',
    ];

    public function handle(ClientRepository $clients): int
    {
        if ($this->option('fresh')) {
            Client::query()->where('name', 'like', 'Khana %')->update(['revoked' => true]);
            $this->warn('Existing Khana clients revoked.');
        }

        foreach (self::PRODUCTS as $product => $urlKey) {
            $label = strtoupper($product);
            $base = rtrim((string) env($urlKey), '/');

            if ($base === '') {
                $this->warn("Skipping {$label}: {$urlKey} is not set.");

                continue;
            }

            $signIn = $clients->createAuthorizationCodeGrantClient(
                name: "Khana {$label} sign-in",
                redirectUris: [$base.'/sso/callback'],
                confidential: false,
            );

            $machine = $clients->createClientCredentialsGrantClient("Khana {$label} machine");
            $machine->forceFill(['scopes' => ['tenants:read', 'roles:read']])->save();

            $this->newLine();
            $this->info("── {$label} ".str_repeat('─', 40));
            $this->line('Paste into apps/'.$product.'/.env:');
            $this->line('');
            $this->line('KHANA_SSO_CLIENT_ID='.$signIn->getKey());
            $this->line('KHANA_MACHINE_CLIENT_ID='.$machine->getKey());
            $this->line('KHANA_MACHINE_CLIENT_SECRET='.$machine->plainSecret);
        }

        $this->newLine();
        $this->info('Done. Each product also needs KHANA_ACCOUNTS_URL and KHANA_ACCOUNTS_PUBLIC_KEY (this app\'s storage/oauth-public.key).');

        return self::SUCCESS;
    }
}
