<?php

namespace App\Http\Controllers;

use App\Mail\SignupOtpMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\SignupOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Confirm the email address with a one-time code before signing up.
 *
 * Both endpoints are public and throttled on the route; the code, its tries
 * and its resend delay are SignupOtp's.
 */
class SignupOtpController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:filter', 'max:150']], [
            'email.email' => 'Enter a valid email address, like name@example.com.',
        ]);

        $email = SignupOtp::normalise($data['email']);

        if (User::query()->withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return $this->error('An account with this email already exists. Try signing in instead.', 422);
        }

        if (($wait = SignupOtp::waitSeconds($email)) > 0) {
            return $this->error("Please wait {$wait} seconds before asking for another code.", 429, ['wait' => $wait]);
        }

        $code = SignupOtp::issue($email);

        try {
            Mail::to($email)->sendNow(new SignupOtpMail($code));
        } catch (Throwable $e) {
            report($e);
            SignupOtp::forget($email);

            return $this->error('We could not send the code just now. Please try again in a minute.', 503);
        }

        // A different address than the one confirmed before is no longer confirmed.
        if ($request->session()->get(SignupOtp::SESSION_KEY) !== $email) {
            $request->session()->forget(SignupOtp::SESSION_KEY);
        }

        return response()->json([
            'success' => true,
            'message' => "We sent a 6-digit code to {$email}. It works for ".SignupOtp::TTL_MINUTES.' minutes.',
            'data' => ['wait' => SignupOtp::RESEND_SECONDS],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:filter', 'max:150'],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], ['otp.regex' => 'Enter the 6-digit code from the email.']);

        $email = SignupOtp::normalise($data['email']);

        $result = SignupOtp::check($email, $data['otp']);

        if ($result !== 'ok') {
            return $this->error(match ($result) {
                'wrong' => 'That code is not right. Check the email and try again.',
                'locked' => 'Too many wrong codes. Ask for a new code.',
                default => 'That code has expired. Ask for a new code.',
            }, 422);
        }

        $request->session()->put(SignupOtp::SESSION_KEY, $email);
        ActivityLog::record('auth.signup_email_verified', "Confirmed {$email} with a code before signing up");

        return response()->json(['success' => true, 'message' => 'Email verified. You can complete your registration now.']);
    }

    private function error(string $message, int $status, array $data = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['email' => [$message]],
            'data' => $data,
        ], $status);
    }
}
