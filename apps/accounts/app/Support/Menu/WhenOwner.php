<?php

namespace App\Support\Menu;

use App\Models\User;

/**
 * A sidebar entry that only the business owner sees. Presentation only - the
 * route behind it checks the same thing (the `owner` middleware).
 */
final class WhenOwner
{
    public function __invoke(array $item): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner();
    }
}
