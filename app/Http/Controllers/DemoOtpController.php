<?php

namespace App\Http\Controllers;

use App\Mail\SignupOtpMail;
use App\Support\SignupOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Confirm an email address with a one-time code before a "Book a demo" request
 * that gives one. The same code mechanism as sign-up (SignupOtp), kept apart
 * from it so the two never share a code or a confirmed address.
 */
class DemoOtpController extends Controller
{
    public const SESSION_KEY = 'demo_otp_verified';

    private const SCOPE = 'demo';

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:filter', 'max:150']], [
            'email.email' => 'Enter a valid email address, like name@example.com.',
        ]);

        $email = SignupOtp::normalise($data['email']);

        if (($wait = SignupOtp::waitSeconds($email, self::SCOPE)) > 0) {
            return $this->error("Please wait {$wait} seconds before asking for another code.", 429, ['wait' => $wait]);
        }

        $code = SignupOtp::issue($email, self::SCOPE);

        try {
            Mail::to($email)->sendNow(new SignupOtpMail($code));
        } catch (Throwable $e) {
            report($e);
            SignupOtp::forget($email, self::SCOPE);

            return $this->error('We could not send the code just now. Please try again in a minute.', 503);
        }

        if ($request->session()->get(self::SESSION_KEY) !== $email) {
            $request->session()->forget(self::SESSION_KEY);
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

        $result = SignupOtp::check($email, $data['otp'], self::SCOPE);

        if ($result !== 'ok') {
            return $this->error(match ($result) {
                'wrong' => 'That code is not right. Check the email and try again.',
                'locked' => 'Too many wrong codes. Ask for a new code.',
                default => 'That code has expired. Ask for a new code.',
            }, 422);
        }

        $request->session()->put(self::SESSION_KEY, $email);

        return response()->json(['success' => true, 'message' => 'Email verified.']);
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
