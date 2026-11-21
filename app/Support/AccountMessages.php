<?php

namespace App\Support;

/**
 * Words the sign-in and the pages around it use when an account is not open yet.
 * One place, so the form, the signed-out redirect and the tests say the same thing.
 */
final class AccountMessages
{
    /** Shown to somebody who has the right password but has not confirmed the address. */
    public static function unverified(string $email): string
    {
        return "Please verify your email first. We sent a verification link to {$email} - open it to activate your account, then sign in. "
            .'Not in your inbox? Check the spam folder, or send the link again.';
    }
}
