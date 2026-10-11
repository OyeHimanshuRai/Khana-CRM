<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Passport\Client;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Passport\ClientRepository;

/**
 * Keys for other billing software.
 *
 * A restaurant can run ERP without CRM: its own billing software sends each
 * sale to ERP's sales API, the same door CRM uses. That software needs to
 * prove which outlet it speaks for, so the owner makes it a key here - a
 * client that may ask for one thing (`sales:write`) and belongs to one
 * outlet. Tokens issued to it carry the outlet's id (see
 * App\Models\Passport\AccessToken), and ERP will not let them write for any
 * other.
 *
 * A key's secret is shown once, when it is made. A key that is revoked gets
 * no more tokens; one already issued runs out within the hour.
 */
final class SalesKeys
{
    public const SCOPES = ['sales:write'];

    public function __construct(private readonly ClientRepository $clients) {}

    /** Other software only has somewhere to send sales if the outlet has ERP. */
    public function allowedFor(Outlet $outlet): bool
    {
        return $outlet->entitlements->contains(
            fn ($entitlement) => $entitlement->product === 'erp' && $entitlement->isHeld(),
        );
    }

    /**
     * The outlet's keys that still work, newest first.
     *
     * @return Collection<int, Client>
     */
    public function of(Outlet $outlet): Collection
    {
        return $this->query($outlet)->where('revoked', false)->latest()->get();
    }

    /**
     * @return array{0: Client, 1: string} the key, and its secret - which nobody can be shown again
     */
    public function make(Outlet $outlet, string $label): array
    {
        /** @var Client $client */
        $client = $this->clients->createClientCredentialsGrantClient($label);

        $secret = (string) $client->plainSecret;

        $client->owner()->associate($outlet);
        $client->forceFill(['scopes' => self::SCOPES])->save();

        return [$client, $secret];
    }

    /** Stops the key being given new tokens. Returns false if it is not this outlet's. */
    public function revoke(Outlet $outlet, string $keyId): bool
    {
        return $this->query($outlet)->whereKey($keyId)->update(['revoked' => true]) > 0;
    }

    private function query(Outlet $outlet)
    {
        return Client::query()
            ->where('owner_type', $outlet->getMorphClass())
            ->where('owner_id', $outlet->id);
    }
}
