<?php

namespace Khana\Kit\Events\Inbox;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Khana\Kit\Events\Inbox\Jobs\ProcessInbox;
use Khana\Kit\Events\Signature;

/**
 * POST /internal/events - where the other services deliver.
 *
 * Outside the web middleware on purpose: no session and no CSRF token, since
 * the caller is a server. The signature is the authentication, and Caddy
 * only lets /internal/* in from our own servers besides.
 */
final class InboxController
{
    public function __invoke(Request $request, Receiver $receiver): JsonResponse
    {
        try {
            $result = $receiver->receive(
                (string) $request->header(Signature::SOURCE_HEADER, ''),
                $request->header(Signature::HEADER),
                $request->getContent(),
            );
        } catch (ReceiveRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        if ($result['stored'] > 0) {
            ProcessInbox::dispatch();
        }

        return response()->json($result);
    }
}
