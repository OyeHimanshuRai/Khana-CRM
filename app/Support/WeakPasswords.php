<?php

namespace App\Support;

/**
 * Passwords every guessing list tries first.
 *
 * Not a password policy (Password::defaults() is that); this is the short list
 * of values that ship in READMEs, seeders and tutorials, which is how a
 * "default admin" account ends up open to anybody who has read one. Checked
 * once, at sign-in, while the plain text is in hand - never stored.
 */
final class WeakPasswords
{
    /** @var array<int, string> */
    public const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', 'admin', 'admin123', 'admin@123', 'administrator',
        'secret', 'letmein', 'welcome', 'welcome1', 'qwerty', 'qwerty123', 'changeme', 'default',
        '12345678', '123456789', '1234567890', '11111111', '00000000', 'abcd1234',
    ];

    public static function matches(string $plain): bool
    {
        return in_array(strtolower($plain), self::COMMON, true);
    }
}
