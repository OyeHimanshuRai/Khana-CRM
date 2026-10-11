<?php

use App\Support\Menu\WhenOwner;
use App\Support\Menu\WhenPlatform;

/*
| The Accounts sidebar (Kit\...\partials\sidebar reads config('menu')).
|
| Deliberately short. Accounts is a hub, not a second admin panel: an owner
| runs their business in four screens, and platform staff have three more.
| Everything else - the day's trading - lives in CRM and ERP.
*/
return [
    [
        'heading' => null,
        'items' => [
            ['label' => 'Home', 'route' => 'home', 'icon' => 'home', 'active' => 'home'],
        ],
    ],
    [
        'heading' => 'Your business',
        'items' => [
            ['label' => 'Business', 'route' => 'business.edit', 'icon' => 'building', 'active' => 'business.*', 'when' => WhenOwner::class],
            ['label' => 'Outlets', 'route' => 'outlets.index', 'icon' => 'store', 'active' => 'outlets.*', 'when' => WhenOwner::class],
            ['label' => 'Team', 'route' => 'team.index', 'icon' => 'users', 'active' => 'team.*', 'when' => WhenOwner::class],
            ['label' => 'Plan & billing', 'route' => 'billing.index', 'icon' => 'credit-card', 'active' => 'billing.*', 'when' => WhenOwner::class],
        ],
    ],
    [
        'heading' => 'Platform',
        'items' => [
            ['label' => 'Businesses', 'route' => 'admin.tenants.index', 'icon' => 'layers', 'active' => 'admin.tenants.*', 'when' => WhenPlatform::class],
            ['label' => 'Plans', 'route' => 'admin.plans.index', 'icon' => 'credit-card', 'active' => 'admin.plans.*', 'when' => WhenPlatform::class],
            ['label' => 'Security', 'route' => 'admin.security.index', 'icon' => 'key', 'active' => 'admin.security.*', 'when' => WhenPlatform::class],
        ],
    ],
];
