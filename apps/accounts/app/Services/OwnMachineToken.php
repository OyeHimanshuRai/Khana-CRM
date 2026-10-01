<?php

namespace App\Services;

use Khana\Kit\Support\Ulid;
use Laravel\Passport\Passport;
use RuntimeException;

/**
 * The token Accounts presents when it is the one asking a product something.
 *
 * CRM and ERP ask Accounts for their machine tokens over HTTP. Accounts is
 * where those tokens come from, so it does not ask anybody: it signs one
 * itself, with the same private key Passport signs every other token with,
 * in the shape the products already check (RS256, `scopes`, a short life).
 * No client, no secret to configure, and no request from Accounts to
 * Accounts - which a single-worker server could never answer.
 *
 * Good for five minutes and for exactly the scopes asked for. A product
 * checks it with Accounts' public key, like any other.
 */
final class OwnMachineToken
{
    private const LIFE_SECONDS = 300;

    /**
     * @param  list<string>  $scopes
     */
    public function for(array $scopes): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        if ($key === false) {
            throw new RuntimeException('Accounts has no signing key. Run `php artisan passport:keys`.');
        }

        $now = time();

        $input = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256'])).'.'.self::encode((string) json_encode([
            'aud' => 'accounts',
            'jti' => Ulid::generate(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::LIFE_SECONDS,
            'sub' => '',
            'scopes' => array_values($scopes),
        ]));

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Accounts could not sign a token with its key.');
        }

        return $input.'.'.self::encode($signature);
    }

    /** Where Passport keeps it: PASSPORT_PRIVATE_KEY, or storage/oauth-private.key. */
    private function privateKey(): string
    {
        $configured = str_replace('\\n', "\n", (string) config('passport.private_key'));

        if ($configured !== '') {
            return $configured;
        }

        $file = Passport::keyPath('oauth-private.key');

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
