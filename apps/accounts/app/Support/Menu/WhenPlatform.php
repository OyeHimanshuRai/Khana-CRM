<?php

namespace App\Support\Menu;

use App\Models\User;

/**
 * A sidebar entry only platform staff see: the back office. The route behind
 * it checks the same thing (the `platform` middleware).
 */
final class WhenPlatform
{
    public function __invoke(array $item): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }
}
