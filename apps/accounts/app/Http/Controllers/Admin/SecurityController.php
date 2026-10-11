<?php

namespace App\Http\Controllers\Admin;

use App\Models\BlockedIp;
use App\Models\LoginRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one door both products open, watched from one place: recent sign-ins,
 * good and bad, and the addresses turned away. Every product's sign-in comes
 * through Accounts, so this is where it is seen.
 */
final class SecurityController
{
    public function index(): View
    {
        return view('admin.security.index', [
            'recent' => LoginRecord::with('user')->latest('created_at')->limit(100)->get(),
            'blocked' => BlockedIp::with('user')->latest()->get(),
        ]);
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        BlockedIp::create($data + ['created_by' => $request->user()->id]);

        return back()->with('status', "Address {$data['ip_address']} blocked.");
    }

    public function unblock(BlockedIp $blockedIp): RedirectResponse
    {
        $blockedIp->delete();

        return back()->with('status', 'Address unblocked.');
    }
}
