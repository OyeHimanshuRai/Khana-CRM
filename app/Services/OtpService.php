<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\TableSession;
use App\Models\VerificationCode;
use App\Services\Sms\SmsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Proving a guest owns the phone they typed in (§3.8).
 *
 * ---------------------------------------------------------------------------
 * What this is actually for
 * ---------------------------------------------------------------------------
 *
 * Not security. Anybody sitting at the table can scan the QR, and nothing
 * here stops them. It is for prank orders: somebody who walks past a window,
 * scans a code and sends forty biryanis to a table they are not sitting at.
 * A restaurant that has had that happen once turns this on; one that has not
 * should not be made to.
 *
 * Which is why it is per-branch and off by default, and why every refusal
 * below leaves the guest able to call a waiter over instead.
 *
 * ---------------------------------------------------------------------------
 * Once per sitting
 * ---------------------------------------------------------------------------
 *
 * The verification lands on the table session, not the order. A table that
 * verified with its starters is not asked again for its mains - the same
 * rule the guest's name already follows.
 */
class OtpService
{
    public function __construct(private readonly SmsManager $sms) {}

    /**
     * Does this sitting need to verify before its order goes to the kitchen?
     *
     * Three things have to be true, and the third is the one people forget:
     * a branch can ask for OTP, but if nothing can send a message then
     * insisting on one would simply close the restaurant. In that case the
     * step is skipped and the misconfiguration is reported, because a guest
     * who cannot order is a worse outcome than a prank order.
     */
    public function isRequired(Shop $shop, TableSession $session): bool
    {
        if (! $shop->requires_otp) {
            return false;
        }

        if ($session->mobile_verified_at !== null) {
            return false;
        }

        if (! $this->sms->isLive()) {
            report(new RuntimeException(sprintf(
                'Branch "%s" asks for OTP confirmation but no SMS gateway is configured; the step was skipped.',
                $shop->name,
            )));

            return false;
        }

        return true;
    }

    /**
     * Send a code, or say why not.
     *
     * Returns the row so the caller can tell the guest where it went and
     * when they may ask again.
     *
     * @throws RuntimeException with a message written for a guest
     */
    public function send(
        string $mobile,
        string $purpose,
        ?Model $verifiable = null,
        ?string $shopName = null,
        ?string $ip = null,
    ): VerificationCode {
        $mobile = $this->normalise($mobile);

        if (strlen(preg_replace('/\D/', '', $mobile) ?? '') < 10) {
            throw new RuntimeException('That does not look like a mobile number. Please check it and try again.');
        }

        /*
         | The resend throttle, read off the last code rather than a cache.
         |
         | A cache would be cleared by a deploy, and a throttle that resets
         | when somebody restarts a worker is not a throttle - it is a way to
         | send a thousand messages at somebody else's expense.
         */
        $last = VerificationCode::query()
            ->where('destination', $mobile)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if ($last !== null && ($wait = $last->resendWaitSeconds()) > 0) {
            throw new RuntimeException("Please wait {$wait} more second".($wait === 1 ? '' : 's').' before asking for another code.');
        }

        $code = $this->mint();

        $row = VerificationCode::create([
            'verifiable_type' => $verifiable?->getMorphClass(),
            'verifiable_id' => $verifiable?->getKey(),
            'purpose' => $purpose,
            'channel' => 'sms',
            'destination' => $mobile,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('sms.otp.ttl_minutes', 10)),
            'ip' => $ip,
        ]);

        $message = strtr((string) config('sms.otp.message'), [
            ':code' => $code,
            ':shop' => $shopName ?: config('app.name'),
            ':minutes' => (string) config('sms.otp.ttl_minutes', 10),
        ]);

        if ($this->sms->gateway()->send($mobile, $message)) {
            $row->forceFill(['sent_at' => now()])->save();

            return $row;
        }

        /*
         | The provider refused. The row stays - `sent_at` null records that
         | we tried and failed, which is a different thing from never having
         | tried and is what somebody reading this table later needs to know.
         */
        throw new RuntimeException('We could not send the code just now. Please try again, or ask a member of staff.');
    }

    /**
     * Check a code a guest typed in.
     *
     * Every failure costs an attempt, including a wrong one against a code
     * that has already expired - otherwise "expired" becomes a free oracle
     * telling somebody which of their guesses to keep.
     *
     * @throws RuntimeException with a message written for a guest
     */
    public function verify(string $mobile, string $code, string $purpose): VerificationCode
    {
        $mobile = $this->normalise($mobile);

        /*
         | Charging the attempt and judging the guess are two phases, and
         | they must not share a transaction.
         |
         | The obvious single-transaction version is wrong in a way that is
         | invisible until somebody attacks it: the increment and the "that
         | code is not right" throw are inside the same transaction, so the
         | throw rolls the increment back. Every wrong guess refunds itself
         | and the limit counts to one, for ever. The fix is to let phase one
         | commit before anything can throw.
         */
        $row = DB::transaction(function () use ($mobile, $purpose) {
            $row = VerificationCode::query()
                ->where('destination', $mobile)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            // Nothing to charge, and nothing to refuse yet - phase two says
            // which of the two it was.
            if ($row === null || $row->isBurnt()) {
                return $row;
            }

            // Charged before the comparison, so a guess costs whether it is
            // right or wrong. Under the lock, so two submissions racing count
            // as two.
            $row->increment('attempts');

            return $row->refresh();
        });

        /* ----------------------------------------------- phase two: judge */

        if ($row === null) {
            throw new RuntimeException('No code is waiting for that number. Ask for a new one.');
        }

        /*
         | Burnt, and it does not matter whether this guess was the right
         | one: a limit that lets the correct code through on the sixth try
         | is only a limit for people who give up.
         */
        if ($row->isBurnt()) {
            throw new RuntimeException('Too many wrong tries. Ask for a new code.');
        }

        if ($row->isExpired()) {
            throw new RuntimeException('That code has expired. Ask for a new one.');
        }

        if (! Hash::check($code, $row->code_hash)) {
            $left = max(0, (int) config('sms.otp.max_attempts', 5) - $row->attempts);

            throw new RuntimeException($left > 0
                ? "That code is not right. {$left} tr".($left === 1 ? 'y' : 'ies').' left.'
                : 'That code is not right, and that was the last try. Ask for a new code.');
        }

        /*
         | Spend it, and only if nobody else already has.
         |
         | The lock was released with phase one, so two correct submissions
         | can reach here together - a guest who double-tapped, or tapped and
         | then reloaded. The conditional update makes exactly one of them the
         | winner; the other is told the code is spent rather than both being
         | quietly allowed.
         */
        $spent = VerificationCode::query()
            ->whereKey($row->id)
            ->whereNull('verified_at')
            ->update(['verified_at' => now()]);

        if ($spent === 0) {
            throw new RuntimeException('That code has already been used. Ask for a new one.');
        }

        return $row->refresh();
    }

    /**
     * Mark a sitting as verified, and remember the number it verified with.
     */
    public function confirmSession(TableSession $session, string $mobile): void
    {
        $session->forceFill([
            'guest_mobile' => $this->normalise($mobile),
            'mobile_verified_at' => now(),
        ])->save();
    }

    /**
     * A fresh code.
     *
     * random_int rather than rand or mt_rand: the others are predictable
     * from a handful of outputs, and "predictable" is the only property a
     * one-time code must not have.
     */
    private function mint(): string
    {
        $length = max(4, min(8, (int) config('sms.otp.length', 6)));

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * One spelling of a number, so the throttle and the lookup agree.
     *
     * "+91 98765 43210", "09876543210" and "9876543210" are one phone, and a
     * resend throttle that thinks they are three is no throttle at all.
     * India's country code is stripped rather than added, because the local
     * ten digits are what every guest types and what every provider wants.
     */
    public function normalise(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
