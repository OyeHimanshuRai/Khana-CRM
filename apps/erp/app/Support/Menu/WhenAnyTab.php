<?php

namespace App\Support\Menu;

use App\Support\Navigation;

/**
 * A hub shows in the sidebar as long as the person may open one of its tabs.
 *
 * Named in config/menu.php as the hub's `when`, which is how the kit's
 * sidebar asks an app a question it cannot answer itself.
 */
final class WhenAnyTab
{
    public function __invoke(array $item): bool
    {
        return Navigation::visibleTabs($item) !== [];
    }
}
