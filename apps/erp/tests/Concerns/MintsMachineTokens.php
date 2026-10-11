<?php

namespace Tests\Concerns;

use Khana\Kit\Support\Ulid;

/**
 * Machine tokens the way Accounts issues them: RS256, signed with a key
 * whose public half ERP is configured with.
 *
 * The keys are generated here rather than committed, as the kit's own SSO
 * tests do: a private key in the repository is a private key in the
 * repository.
 */
trait MintsMachineTokens
{
    /** @var array<string, string> */
    private static array $machineKeys = [];

    protected function trustAccountsKey(): void
    {
        config(['khana.sso.public_key' => self::machineKey('accounts-public')]);
    }

    /** "accounts-private", "accounts-public" or "stranger-private". */
    private static function machineKey(string $name): string
    {
        if (self::$machineKeys === []) {
            // Windows PHP ships its openssl.cnf beside the binary.
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
                + (is_file($config) ? ['config' => $config] : []);

            foreach (['accounts', 'stranger'] as $who) {
                $pair = openssl_pkey_new($options);
                openssl_pkey_export($pair, $private, null, $options);

                self::$machineKeys[$who.'-private'] = $private;
                self::$machineKeys[$who.'-public'] = openssl_pkey_get_details($pair)['key'];
            }
        }

        return self::$machineKeys[$name];
    }

    /**
     * A client-credentials token shaped the way Passport issues one.
     *
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims  e.g. ['outlet_id' => ...] for a key bound to one outlet
     */
    protected function machineToken(array $scopes, array $claims = [], string $signer = 'accounts-private'): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $input = $encode(json_encode(['typ' => 'JWT', 'alg' => 'RS256'])).'.'.$encode(json_encode(array_replace([
            'aud' => 'crm-machine',
            'jti' => 'token-'.Ulid::generate(),
            'iat' => time() - 10,
            'nbf' => time() - 10,
            'exp' => time() + 3600,
            'sub' => '',
            'scopes' => $scopes,
        ], $claims)));

        openssl_sign($input, $signature, self::machineKey($signer), OPENSSL_ALGO_SHA256);

        return $input.'.'.$encode($signature);
    }

    /** @return array<string, string> */
    protected function bearer(array $scopes, array $claims = [], string $signer = 'accounts-private'): array
    {
        return ['Authorization' => 'Bearer '.$this->machineToken($scopes, $claims, $signer)];
    }
}
