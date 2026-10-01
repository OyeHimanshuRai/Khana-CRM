<?php

namespace App\Http\Controllers\Owner;

use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleCatalog;
use App\Services\Team;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The people of a business. An owner invites someone once, picks their role
 * in CRM and ERP and the outlets they reach, and both products learn of them
 * before they have signed in.
 *
 * The roles on offer are each product's presets and, after them, whatever
 * roles the business has made for itself inside that product
 * (App\Services\RoleCatalog).
 */
final class TeamController
{
    public function __construct(private readonly RoleCatalog $roles) {}

    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $tenant->loadMissing(['users.access.outlets']);

        return view('owner.team.index', [
            'members' => $tenant->users->sortByDesc('is_owner')->values(),
            'roles' => $this->roles->for($tenant),
        ]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->user()->tenant;

        return view('owner.team.form', [
            'member' => null,
            'roles' => $this->roles->for($tenant),
            'outlets' => $tenant->outlets()->orderBy('name')->get(),
            'access' => [],
        ]);
    }

    public function store(Request $request, Team $team): RedirectResponse
    {
        $data = $this->validated($request);
        $tenant = $request->user()->tenant;

        [$user, $token] = $team->invite(
            $tenant,
            ['name' => $data['name'], 'email' => $data['email'] ?? null, 'mobile' => $data['mobile'] ?? null],
            $this->accessFrom($data, $tenant),
            $request->user(),
        );

        // Shown once so the owner can pass the link on if the email bounces.
        $link = route('invite.show', $token);

        return redirect()->route('team.index')
            ->with('status', "Invited {$user->name}.")
            ->with('invite_link', $link);
    }

    public function edit(User $member, Request $request): View
    {
        $this->authorizeMember($member, $request);
        $member->loadMissing('access.outlets');

        $tenant = $request->user()->tenant;

        return view('owner.team.form', [
            'member' => $member,
            'roles' => $this->roles->for($tenant),
            'outlets' => $tenant->outlets()->orderBy('name')->get(),
            'access' => $member->access->keyBy('product'),
        ]);
    }

    public function update(User $member, Request $request, Team $team): RedirectResponse
    {
        $this->authorizeMember($member, $request);

        abort_if($member->is_owner, 403, 'The owner\'s own access cannot be changed here.');

        $data = $request->validate([
            'access' => ['array'],
            'access.*.role_key' => ['nullable', 'string', 'max:40'],
            'access.*.all_outlets' => ['nullable', 'boolean'],
            'access.*.outlet_ids' => ['nullable', 'array'],
        ]);

        $team->updateAccess($member, $this->accessFrom($data, $request->user()->tenant, $member));

        return redirect()->route('team.index')->with('status', "Saved {$member->name}'s access.");
    }

    public function deactivate(User $member, Request $request, Team $team): RedirectResponse
    {
        $this->authorizeMember($member, $request);

        abort_if($member->is_owner, 403, 'The owner cannot be deactivated.');

        $team->deactivate($member);

        return redirect()->route('team.index')->with('status', "{$member->name} has been deactivated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:mobile', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:16', 'required_without:email', 'unique:users,mobile'],
            'access' => ['array'],
            'access.*.role_key' => ['nullable', 'string', 'max:40'],
            'access.*.all_outlets' => ['nullable', 'boolean'],
            'access.*.outlet_ids' => ['nullable', 'array'],
        ]);
    }

    /**
     * Keep only the products the owner actually gave a role, and only roles
     * that product has.
     *
     * A role the person already holds is let through unasked: it may be one
     * the business made in a product that is not answering just now, and
     * saving somebody's outlets must not take their role away.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{role_key: string, all_outlets: bool, outlet_ids: list<int>}>
     *
     * @throws ValidationException
     */
    private function accessFrom(array $data, Tenant $tenant, ?User $member = null): array
    {
        $access = [];
        $held = $member?->access->pluck('role_key', 'product')->all() ?? [];

        foreach ((array) ($data['access'] ?? []) as $product => $grant) {
            if (empty($grant['role_key'])) {
                continue;
            }

            $key = (string) $grant['role_key'];

            if (($held[$product] ?? null) !== $key && ! $this->roles->offers($tenant, (string) $product, $key)) {
                throw ValidationException::withMessages([
                    "access.{$product}.role_key" => 'That is not a role '.(Roles::PRODUCT_NAMES[$product] ?? 'this product').' has. If you made it there a moment ago, open this page again.',
                ]);
            }

            $access[$product] = [
                'role_key' => $key,
                'all_outlets' => (bool) ($grant['all_outlets'] ?? false),
                'outlet_ids' => array_map('intval', (array) ($grant['outlet_ids'] ?? [])),
            ];
        }

        return $access;
    }

    private function authorizeMember(User $member, Request $request): void
    {
        abort_unless($member->tenant_id === $request->user()->tenant_id, 404);
    }
}
