<?php

namespace App\Services\Accounts;

use Khana\Kit\Events\Envelope;
use Khana\Kit\Sso\MachineTokens;
use Khana\Kit\Support\Ulid;
use Throwable;

/**
 * What CRM reads from Accounts beyond sign-in: everything it copies about a
 * business, in one reply (contracts/api/accounts.yaml, operationId
 * tenantSnapshot).
 *
 * Events keep CRM's copy current. This is for the moment they cannot be
 * relied on: the nightly reference check, which is how a lost accounts.*
 * event is found and put right.
 *
 * One attempt, ten seconds, and one exception for every way of not getting
 * an answer. Only the parts CRM keeps for itself - the business, its outlets
 * and what each has bought - are insisted on; the people part of the snapshot
 * is left to sign-in and is not read here.
 */
final class AccountsClient
{
    public function __construct(private readonly MachineTokens $tokens) {}

    /**
     * @return array{tenant_id: string, tenant: array<string, mixed>, outlets: list<array<string, mixed>>, entitlements: list<array<string, mixed>>, taken_at: string}
     *
     * @throws AccountsUnavailable
     */
    public function snapshot(string $tenantId): array
    {
        $accounts = rtrim((string) config('khana.urls.accounts'), '/');

        if ($accounts === '') {
            throw new AccountsUnavailable('No address for Restro One Accounts is set up here.');
        }

        try {
            $http = $this->tokens->http(['tenants:read']);
        } catch (Throwable $e) {
            throw new AccountsUnavailable('Restro One Accounts did not give CRM a pass to read the business.', previous: $e);
        }

        try {
            $response = $http->get($accounts."/api/v1/tenants/{$tenantId}/snapshot");
        } catch (Throwable $e) {
            throw new AccountsUnavailable('Restro One Accounts did not answer.', previous: $e);
        }

        if (! $response->successful()) {
            throw new AccountsUnavailable('Restro One Accounts answered with an error ('.$response->status().').');
        }

        $snapshot = $response->json();

        if (! self::readable($snapshot, $tenantId)) {
            throw new AccountsUnavailable('Restro One Accounts sent a snapshot CRM could not read.');
        }

        return $snapshot;
    }

    /**
     * Enough of the contract to apply the snapshot without tripping over it:
     * the right business, a time it was taken, and the three parts CRM keeps,
     * each present with the keys that say whose row it is.
     */
    private static function readable(mixed $snapshot, string $tenantId): bool
    {
        if (! is_array($snapshot) || ($snapshot['tenant_id'] ?? null) !== $tenantId) {
            return false;
        }

        if (! is_string($snapshot['taken_at'] ?? null) || preg_match(Envelope::TIME_PATTERN, $snapshot['taken_at']) !== 1) {
            return false;
        }

        if (! is_array($snapshot['tenant'] ?? null) || ! isset($snapshot['tenant']['name'], $snapshot['tenant']['status'])) {
            return false;
        }

        foreach (['outlets' => ['outlet_id'], 'entitlements' => ['outlet_id', 'product']] as $part => $keys) {
            if (! is_array($snapshot[$part] ?? null) || ! array_is_list($snapshot[$part])) {
                return false;
            }

            foreach ($snapshot[$part] as $row) {
                if (! is_array($row)) {
                    return false;
                }

                foreach ($keys as $key) {
                    if (! is_string($row[$key] ?? null)) {
                        return false;
                    }
                }

                if (! Ulid::isValid($row['outlet_id'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
