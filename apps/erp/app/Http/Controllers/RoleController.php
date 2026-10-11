<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Here;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The business's own roles.
 *
 * The presets cover most restaurants and cannot be changed. When one does
 * not fit - a head chef who writes recipes and logs wastage but never sees a
 * bill - the owner copies the nearest preset and ticks what should differ.
 *
 * Only what a role opens is decided here. Who holds it is set on the team
 * screen in Khana Accounts, where the new role turns up beside the presets.
 */
final class RoleController
{
    public function index(): View
    {
        $tenant = Here::tenant();
        $accounts = rtrim((string) config('khana.urls.accounts'), '/');

        return view('roles.index', [
            'presets' => Roles::PRESETS,
            'made' => Role::forTenant($tenant)->orderBy('name')->get(),
            // The people ERP has seen, by the role they hold.
            'holders' => User::query()
                ->where('tenant_id', $tenant)
                ->where('is_active', true)
                ->whereNotNull('role_key')
                ->groupBy('role_key')
                ->selectRaw('role_key, COUNT(*) as people')
                ->pluck('people', 'role_key'),
            'teamUrl' => $accounts === '' ? null : $accounts.'/team',
        ]);
    }

    public function create(Request $request): View
    {
        $from = Roles::isPreset($request->query('from')) ? (string) $request->query('from') : 'storekeeper';

        return view('roles.form', [
            'role' => null,
            'from' => $from,
            'presets' => Roles::PRESETS,
            'name' => '',
            'ticked' => self::copyOf($from),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Here::tenant();
        [$name, $abilities] = $this->validated($request, $tenant);

        $from = $request->input('based_on');

        $role = Role::create([
            'tenant_id' => $tenant,
            'key' => Roles::keyFor($name, $tenant),
            'name' => $name,
            'abilities' => $abilities,
            'based_on' => Roles::isPreset($from) ? $from : null,
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('roles.index')
            ->with('status', '"'.$role->name.'" is ready. Give it to people on the team screen in Khana Accounts.');
    }

    public function edit(int $role): View
    {
        $role = Role::forTenant(Here::tenant())->findOrFail($role);

        return view('roles.form', [
            'role' => $role,
            'from' => $role->based_on,
            'presets' => Roles::PRESETS,
            'name' => $role->name,
            'ticked' => (array) $role->abilities,
        ]);
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $tenant = Here::tenant();
        $role = Role::forTenant($tenant)->findOrFail($role);
        [$name, $abilities] = $this->validated($request, $tenant, $role);

        // The key stays: it is what Accounts holds against each person.
        $role->update(['name' => $name, 'abilities' => $abilities]);

        return redirect()->route('roles.index')
            ->with('status', '"'.$role->name.'" saved. It applies to everybody who holds it from their next click.');
    }

    public function destroy(int $role): RedirectResponse
    {
        $tenant = Here::tenant();
        $role = Role::forTenant($tenant)->findOrFail($role);

        $holders = User::query()->where('tenant_id', $tenant)->where('is_active', true)->where('role_key', $role->key)->pluck('name');

        if ($holders->isNotEmpty()) {
            return back()->with('error', '"'.$role->name.'" is held by '.$holders->take(3)->implode(', ')
                .($holders->count() > 3 ? ' and '.($holders->count() - 3).' more' : '')
                .'. Give them another role in Khana Accounts first.');
        }

        $role->delete();

        return redirect()->route('roles.index')->with('status', '"'.$role->name.'" deleted.');
    }

    /**
     * @return array{0: string, 1: list<string>} the name, and the abilities with what each needs added
     *
     * @throws ValidationException
     */
    private function validated(Request $request, string $tenant, ?Role $editing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
        ]);

        $name = trim($data['name']);
        $taken = collect(Roles::PRESETS)->pluck('name')
            ->merge(Role::forTenant($tenant)->when($editing, fn ($q) => $q->whereKeyNot($editing->id))->pluck('name'))
            ->contains(fn (string $other) => mb_strtolower($other) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a role called "'.$name.'". Two with one name could not be told apart on the team screen.']);
        }

        $abilities = Roles::grantable($data['abilities'] ?? []);

        if ($abilities === []) {
            throw ValidationException::withMessages(['abilities' => 'Tick at least one thing this role may do.']);
        }

        return [$name, $abilities];
    }

    /**
     * What a copy of a preset starts with. The owner's is "everything", so
     * its copy is everything a made role may hold.
     *
     * @return list<string>
     */
    private static function copyOf(string $preset): array
    {
        $can = Roles::PRESETS[$preset]['can'];

        return Roles::grantable($can === ['*'] ? array_keys(Roles::ABILITIES) : $can);
    }
}
