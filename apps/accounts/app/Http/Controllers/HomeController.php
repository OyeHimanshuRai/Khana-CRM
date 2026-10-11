<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a person lands after signing in. Platform staff go to the back
 * office; an owner sees their business at a glance; anyone else sees where
 * their products are, since Accounts is not where they do their day.
 */
final class HomeController
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            return redirect()->route('admin.tenants.index');
        }

        if ($user->isOwner()) {
            $tenant = $user->tenant;
            $tenant->loadMissing(['outlets.subscriptions.plan', 'outlets.entitlements', 'users']);

            return view('home.owner', [
                'tenant' => $tenant,
                'outlets' => $tenant->outlets,
            ]);
        }

        $user->loadMissing('access');

        return view('home.member', [
            'user' => $user,
            'products' => $user->access->pluck('product')->all(),
        ]);
    }
}
