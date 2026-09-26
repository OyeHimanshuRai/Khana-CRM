<?php

namespace Khana\Kit\Sso;

use RuntimeException;

/**
 * Checks a service-to-service token locally, with Accounts' public key.
 *
 * Accounts (Passport) signs every token with its private key; each product
 * holds only the public half. So a product can check a token without calling
 * Accounts - which keeps working while Accounts is down - and nobody who
 * steals a product's configuration can mint tokens.
 *
 * Only RS256 is accepted. A token that names another algorithm is refused
 * outright, which is what closes the "alg: none" and HMAC-with-the-public-key
 * tricks.
 */
final class MachineTokenVerifier
{
    /**
     * @param  list<string>  $requiredScopes
     *
     * @throws InvalidMachineToken
     */
    public function verify(string $jwt, array $requiredScopes = []): MachineToken
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new InvalidMachineToken('Not a signed token.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = json_decode((string) self::decode($encodedHeader), true);
        $claims = json_decode((string) self::decode($encodedClaims), true);
        $signature = self::decode($encodedSignature);

        if (! is_array($header) || ! is_array($claims) || $signature === null) {
            throw new InvalidMachineToken('Not a signed token.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new InvalidMachineToken('Only RS256 tokens are accepted.');
        }

        if (openssl_verify($encodedHeader.'.'.$encodedClaims, $signature, $this->publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidMachineToken('The token was not signed by Accounts.');
        }

        $now = time();
        $leeway = (int) config('khana.sso.leeway', 30);

        if (! is_numeric($claims['exp'] ?? null) || (float) $claims['exp'] < $now - $leeway) {
            throw new InvalidMachineToken('The token has expired.');
        }

        if (is_numeric($claims['nbf'] ?? null) && (float) $claims['nbf'] > $now + $leeway) {
            throw new InvalidMachineToken('The token is not valid yet.');
        }

        $scopes = $claims['scopes'] ?? (isset($claims['scope']) ? explode(' ', (string) $claims['scope']) : []);
        $scopes = array_values(array_filter(array_map('strval', (array) $scopes)));

        $audience = $claims['aud'] ?? '';

        $token = new MachineToken(
            clientId: (string) (is_array($audience) ? ($audience[0] ?? '') : $audience),
            scopes: $scopes,
            subject: isset($claims['sub']) && $claims['sub'] !== '' ? (string) $claims['sub'] : null,
            outletId: isset($claims['outlet_id']) ? (string) $claims['outlet_id'] : null,
            tokenId: isset($claims['jti']) ? (string) $claims['jti'] : null,
            expiresAt: (int) $claims['exp'],
        );

        foreach ($requiredScopes as $scope) {
            if (! $token->can($scope)) {
                throw new InsufficientScope("The token may not {$scope}.");
            }
        }

        return $token;
    }

    private function publicKey(): \OpenSSLAsymmetricKey
    {
        $configured = (string) config('khana.sso.public_key', '');

        if ($configured !== '' && ! str_contains($configured, '-----BEGIN') && is_file($configured)) {
            $configured = (string) file_get_contents($configured);
        }

        // A key pasted into .env usually arrives with literal "\n"s.
        $key = openssl_pkey_get_public(str_replace('\n', "\n", $configured));

        if ($key === false) {
            throw new RuntimeException('KHANA_ACCOUNTS_PUBLIC_KEY is not set to Accounts\' public key.');
        }

        return $key;
    }

    private static function decode(string $part): ?string
    {
        $decoded = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
