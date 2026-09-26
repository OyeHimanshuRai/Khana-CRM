<?php

namespace Khana\Kit\Ui;

use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The switcher in CRM and ERP, from the local copy of what the outlet owns.
 *
 * The link to the other product carries the outlet's id, so the person lands
 * on the same outlet there. If they have no session there yet, Accounts signs
 * them in silently on the way.
 */
final class EntitledProductSwitcher implements ProductSwitcher
{
    public function __construct(
        private readonly Context $context,
        private readonly Entitlements $entitlements,
    ) {}

    public function links(): array
    {
        $here = (string) config('khana.service');
        $outlet = $this->context->outletId();
        $outlets = $outlet !== null ? [$outlet] : $this->context->accessibleOutlets();
        $platform = (bool) $this->context->actor()?->isPlatformAdmin();

        $links = [[
            'key' => 'accounts',
            'label' => 'Business & team',
            'hint' => 'Outlets, people and plan',
            'url' => self::url('accounts', '/'),
            'current' => $here === 'accounts',
        ]];

        foreach (['crm' => ['CRM', 'Tables, orders, billing'], 'erp' => ['ERP', 'Stock, recipes, purchases']] as $product => [$label, $hint]) {
            $owned = $platform || array_filter($outlets, fn ($id) => $this->entitlements->owns($id, $product)) !== [];

            $links[] = [
                'key' => $product,
                'label' => $label,
                'hint' => $owned ? $hint : 'Not on this outlet\'s plan',
                'url' => ! $owned ? null : ($product === $here
                    ? url('/')
                    : self::url($product, '/khana/open'.($outlet !== null ? '?outlet='.rawurlencode((string) $outlet) : ''))),
                'current' => $product === $here,
            ];
        }

        return $links;
    }

    /** Another product's address, or null when this install has not been told it. */
    public static function url(string $product, string $path): ?string
    {
        $base = config("khana.urls.{$product}");

        return is_string($base) && $base !== '' ? rtrim($base, '/').$path : null;
    }
}
