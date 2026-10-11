<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;

/**
 * What Accounts says about people between their sign-ins.
 *
 * A person's row is refreshed whenever they sign in, which would be enough
 * if nothing changed while they were signed in. It does: an owner takes an
 * outlet away from a storekeeper mid-shift, or deactivates somebody who has
 * left. These two events bring the local row in line straight away.
 *
 * Only people ERP has already seen are touched. Somebody who has never
 * signed in here gets their row at sign-in, from the same source.
 */
final class PeopleHandlers
{
    public static function register(Handlers $handlers): void
    {
        $handlers->on('accounts.user.upserted', 1, fn (Envelope $e) => app(self::class)->person($e));
        $handlers->on('accounts.access.changed', 1, fn (Envelope $e) => app(self::class)->access($e));
    }

    public function person(Envelope $event): void
    {
        $user = User::query()->where('ulid', $event->data['user_id'])->first();

        if ($user === null) {
            return;
        }

        $user->forceFill([
            'name' => $event->data['name'],
            'email' => $event->data['email'] ?? null,
            'mobile' => $event->data['mobile'] ?? null,
            'is_active' => $event->data['status'] !== 'deactivated',
        ])->saveQuietly();
    }

    public function access(Envelope $event): void
    {
        if (($event->data['product'] ?? null) !== 'erp') {
            return;
        }

        $user = User::query()->where('ulid', $event->data['user_id'])->first();

        if ($user === null) {
            return;
        }

        $role = $event->data['role_key'] ?? null;

        // A worker applying events lives a long time; a role made on a
        // screen since it last looked would otherwise be unknown to it.
        Roles::forget();

        /*
         | No role, or one ERP has no rights for, is no access: the row keeps
         | the person's name on the things they did, but opens nothing, and
         | whatever they have open is closed.
         */
        if (! Roles::exists($role, $user->tenant_id)) {
            $user->forceFill(['role_key' => null, 'outlet_ids' => []])->saveQuietly();
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

            return;
        }

        $user->forceFill([
            'role_key' => $role,
            'outlet_ids' => array_values($event->data['outlet_ids'] ?? []),
        ])->saveQuietly();
    }
}
