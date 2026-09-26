<?php

namespace Khana\Kit\Sso;

use Carbon\CarbonImmutable;
use Khana\Kit\Support\Ulid;

/**
 * Who Accounts says just signed in: their profile, their outlets, and their
 * role in each product (GET /api/v1/me - see contracts/api/accounts.yaml).
 *
 * The product decides what to do with it - start its own session for the
 * matching local user - but never decides who the person is. That is
 * Accounts' answer alone.
 */
final class SignedInPerson
{
    /**
     * @param  list<array{outlet_id: string, name: string, code: string}>  $outlets
     * @param  array<string, array{role_key: string, outlet_ids: list<string>}>  $products
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $mobile,
        public readonly ?string $tenantId,
        public readonly bool $platformAdmin,
        public readonly array $outlets,
        public readonly array $products,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $profile  the /api/v1/me body
     * @param  array<string, mixed>  $token  the /oauth/token body
     */
    public static function fromAccounts(array $profile, array $token): self
    {
        if (! Ulid::isValid($profile['user_id'] ?? null) || ! is_string($profile['name'] ?? null)) {
            throw new SignInFailed('Accounts returned a profile without a user id or name.');
        }

        $tenantId = $profile['tenant_id'] ?? null;

        if ($tenantId !== null && ! Ulid::isValid($tenantId)) {
            throw new SignInFailed('Accounts returned a malformed business id.');
        }

        $products = [];

        foreach ((array) ($profile['products'] ?? []) as $product => $access) {
            if (is_array($access) && is_string($access['role_key'] ?? null)) {
                $products[(string) $product] = [
                    'role_key' => $access['role_key'],
                    'outlet_ids' => array_values(array_filter((array) ($access['outlet_ids'] ?? []), [Ulid::class, 'isValid'])),
                ];
            }
        }

        $expiresIn = $token['expires_in'] ?? null;

        return new self(
            userId: $profile['user_id'],
            name: $profile['name'],
            email: $profile['email'] ?? null,
            mobile: $profile['mobile'] ?? null,
            tenantId: $tenantId,
            platformAdmin: (bool) ($profile['platform_admin'] ?? false),
            outlets: array_values((array) ($profile['outlets'] ?? [])),
            products: $products,
            accessToken: (string) $token['access_token'],
            refreshToken: isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            expiresAt: is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
        );
    }

    /** Whether this person may use the product at all. */
    public function canUse(string $product): bool
    {
        return $this->platformAdmin || isset($this->products[$product]);
    }

    public function roleIn(string $product): ?string
    {
        return $this->products[$product]['role_key'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function outletIdsIn(string $product): array
    {
        return $this->products[$product]['outlet_ids'] ?? [];
    }
}
