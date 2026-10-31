<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Do this once", however many times the request arrives.
 *
 * A till on café Wi-Fi sends a sale, the answer is lost, the cashier presses
 * again - and there are two invoices, two stock deductions, two payments. A
 * guest's thumb lands twice and the kitchen gets two tickets. The button
 * disabling itself does not help once the first response never came back: the
 * page cannot know whether the sale happened.
 *
 * So the page names each attempt (a random key, sent as `Idempotency-Key` or,
 * for a plain form post, as the hidden `_idempotency_key` field), and this
 * middleware makes the name count:
 *
 *   - the first request with a key runs, and its answer is remembered
 *   - the same key again gets that same answer back - nothing runs twice
 *   - the same key arriving while the first is still running is refused (409),
 *     which is a double tap, not a second wish
 *   - the same key with a different body is refused (422): that is a client
 *     bug, and quietly returning the old answer would hide it
 *
 * Only on the routes that write something that cannot be written twice (a sale,
 * a payment, a goods receipt, a restock, an order). A request with no key behaves
 * exactly as it did before, so nothing that does not send one is affected.
 *
 * Only a success is remembered. A refused request (422, 403) did nothing, so the
 * same attempt - corrected - may run.
 */
class EnsureIdempotent
{
    /** How long an answer is kept. Long enough for any retry; short enough to forget. */
    private const KEEP_FOR_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('_idempotency_key');

        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-]{16,100}$/', $key)) {
            return $next($request);
        }

        $who = $request->user()?->getAuthIdentifier()
            ?? ($request->hasSession() ? $request->session()->getId() : null)
            ?? $request->ip();

        // Scoped to who is asking and which route, so a key can never reach another
        // person's answer or another operation's.
        $id = 'idem:'.hash('sha256', $who.'|'.$request->method().'|'.($request->route()?->getName() ?? $request->path()).'|'.$key);
        $fingerprint = hash('sha256', json_encode($request->except(['_token', '_idempotency_key'])));

        $stored = Cache::get($id);

        if (is_array($stored)) {
            if (! hash_equals($stored['fingerprint'], $fingerprint)) {
                return $this->refuse($request, 'That request key was already used for a different request.', 422);
            }

            return $this->replay($stored);
        }

        $lock = Cache::lock($id.':lock', 60);

        if (! $lock->get()) {
            return $this->refuse($request, 'This is already being processed. Give it a moment.', 409);
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 400) {
                $entry = $this->remember($response, $fingerprint);

                if ($entry !== null) {
                    Cache::put($id, $entry, now()->addHours(self::KEEP_FOR_HOURS));
                }
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** What to keep of an answer, or null for a kind that cannot be replayed faithfully. */
    private function remember(Response $response, string $fingerprint): ?array
    {
        if ($response instanceof JsonResponse) {
            return [
                'fingerprint' => $fingerprint,
                'type' => 'json',
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
            ];
        }

        if ($response instanceof RedirectResponse) {
            return [
                'fingerprint' => $fingerprint,
                'type' => 'redirect',
                'status' => $response->getStatusCode(),
                'url' => $response->getTargetUrl(),
            ];
        }

        return null;
    }

    private function replay(array $stored): Response
    {
        $response = $stored['type'] === 'redirect'
            ? redirect()->to($stored['url'], $stored['status'])
            : response($stored['body'], $stored['status'], ['Content-Type' => 'application/json']);

        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function refuse(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message, 'errors' => (object) []], $status);
        }

        abort($status, $message);
    }
}
