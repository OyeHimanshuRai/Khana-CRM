<?php

namespace Khana\Kit\Sso;

/**
 * A verified service-to-service token: which client it was issued to, what it
 * may do, and - for a third-party sales key - the one outlet it is bound to.
 */
final class MachineToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly array $scopes,
        public readonly ?string $subject,
        public readonly ?string $outletId,
        public readonly ?string $tokenId,
        public readonly int $expiresAt,
    ) {}

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }
}
