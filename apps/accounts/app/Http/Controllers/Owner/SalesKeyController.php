<?php

namespace App\Http\Controllers\Owner;

use App\Models\Outlet;
use App\Services\SalesKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Making and revoking an outlet's keys for other billing software. They
 * live on the outlet's own page: a key is about one outlet, and that is
 * where an owner looks for it.
 */
final class SalesKeyController
{
    public function store(Outlet $outlet, Request $request, SalesKeys $keys): RedirectResponse
    {
        $this->authorizeOutlet($outlet, $request, $keys);

        $data = $request->validate([
            'software' => ['required', 'string', 'max:60'],
        ], [], ['software' => 'name of the software']);

        [$key, $secret] = $keys->make($outlet, trim($data['software']));

        // Flashed, so it is on the next page and nowhere after it.
        return redirect()->route('outlets.edit', $outlet)->with('new_sales_key', [
            'name' => $key->name,
            'id' => (string) $key->getKey(),
            'secret' => $secret,
        ]);
    }

    public function destroy(Outlet $outlet, string $key, Request $request, SalesKeys $keys): RedirectResponse
    {
        $this->authorizeOutlet($outlet, $request, $keys);

        abort_unless($keys->revoke($outlet, $key), 404);

        return redirect()->route('outlets.edit', $outlet)
            ->with('status', 'Key revoked. It gets no more tokens; one already issued runs out within the hour.');
    }

    private function authorizeOutlet(Outlet $outlet, Request $request, SalesKeys $keys): void
    {
        abort_unless($outlet->tenant_id === $request->user()->tenant_id, 404);

        // Nowhere to send sales to without ERP.
        abort_unless($keys->allowedFor($outlet), 404);
    }
}
