<?php

namespace Khana\Kit\Ui;

/**
 * The products shown in the top bar's switcher.
 *
 * A restaurant running both products should feel it bought one: the switcher
 * lists what the outlet owns, and following a link keeps the same outlet
 * selected because the outlet's id travels with it.
 */
interface ProductSwitcher
{
    /**
     * One entry per product. `url` is null for a product the outlet has not
     * bought, which the switcher shows greyed out rather than hiding, so the
     * owner can see what else there is.
     *
     * @return list<array{key: string, label: string, hint: string, url: string|null, current: bool}>
     */
    public function links(): array;
}
