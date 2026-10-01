<?php

namespace App\Models\Passport;

use App\Models\Outlet;
use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken as PassportAccessToken;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use SensitiveParameter;

/**
 * A token that says which outlet it is for, when its client is a key made
 * for one outlet.
 *
 * Other billing software sends an outlet's sales to ERP with a key the owner
 * made for that outlet (App\Services\SalesKeys). ERP checks tokens on its
 * own, with Accounts' public key, so the binding has to travel inside the
 * token: `outlet_id`, which ERP holds the request to whatever the request
 * itself says. Every other token - a person's, a product's own - is exactly
 * what Passport would have issued.
 */
class AccessToken extends PassportAccessToken
{
    private ?CryptKeyInterface $signingKey = null;

    public function setPrivateKey(#[SensitiveParameter] CryptKeyInterface $privateKey): void
    {
        parent::setPrivateKey($privateKey);

        // Passport keeps its copy private; the bound token below needs one too.
        $this->signingKey = $privateKey;
    }

    public function toString(): string
    {
        $outletId = $this->boundOutlet();

        if ($outletId === null || $this->signingKey === null) {
            return parent::toString();
        }

        $jwt = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->signingKey->getKeyContents(), $this->signingKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );

        $now = new DateTimeImmutable;

        // The same claims Passport writes, and one more.
        return $jwt->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->withClaim('outlet_id', $outletId)
            ->getToken($jwt->signer(), $jwt->signingKey())
            ->toString();
    }

    /** The outlet this token's client was made for, by its id across services. */
    private function boundOutlet(): ?string
    {
        $client = Passport::client()->newQuery()->find($this->getClient()->getIdentifier());

        if ($client === null || $client->owner_type !== (new Outlet)->getMorphClass()) {
            return null;
        }

        return Outlet::query()->whereKey($client->owner_id)->value('ulid');
    }
}
