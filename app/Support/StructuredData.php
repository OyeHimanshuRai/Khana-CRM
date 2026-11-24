<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Setting;

/**
 * schema.org markup for the public pages, so a search engine knows what the site is:
 * the organisation (with the phone and email from Settings), the website, the software
 * and what it costs, and where a page sits in the site.
 */
final class StructuredData
{
    /** @param array<string, mixed> $site SiteBrand::get() */
    public static function organization(array $site): array
    {
        $data = [
            '@type' => 'Organization',
            '@id' => url('/').'#organization',
            'name' => $site['name'],
            'url' => url('/'),
        ];

        if (! empty($site['logo'])) {
            $data['logo'] = $site['logo'];
        }

        $contact = array_filter([
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'telephone' => $site['phone'] ?: null,
            'email' => $site['email'] ?: null,
            'areaServed' => 'IN',
            'availableLanguage' => ['English', 'Hindi'],
        ]);

        if (isset($contact['telephone']) || isset($contact['email'])) {
            $data['contactPoint'] = $contact;
        }

        $same = array_values(array_filter((array) ($site['social'] ?? [])));

        if ($same !== []) {
            $data['sameAs'] = $same;
        }

        return $data;
    }

    /** @param array<string, mixed> $site */
    public static function website(array $site): array
    {
        return ['@type' => 'WebSite', '@id' => url('/').'#website', 'url' => url('/'), 'name' => $site['name'], 'inLanguage' => 'en-IN',
            'publisher' => ['@id' => url('/').'#organization']];
    }

    /** The software, with one offer per plan on sale. */
    public static function software(array $site): array
    {
        $offers = Plan::query()->onSale()->orderBy('sort_order')->orderBy('monthly_price')->get()
            ->map(fn (Plan $plan) => [
                '@type' => 'Offer',
                'name' => $plan->name.' plan (monthly)',
                'price' => number_format($plan->priceFor('monthly'), 2, '.', ''),
                'priceCurrency' => 'INR',
                'url' => route('pricing'),
                'availability' => 'https://schema.org/InStock',
            ])->values()->all();

        $data = [
            '@type' => 'SoftwareApplication',
            'name' => $site['name'],
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Restaurant POS software',
            'operatingSystem' => 'Web browser',
            'url' => url('/'),
            'description' => 'Restaurant POS with table and counter billing, QR ordering, a kitchen display, stock, GST invoices and reports.',
            'publisher' => ['@id' => url('/').'#organization'],
        ];

        if ($offers !== []) {
            $data['offers'] = $offers;
        }

        return $data;
    }

    /** @param array<int, array{0: string, 1: string}> $trail [name, url] from the home page down */
    public static function breadcrumbs(array $trail): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($trail)->values()->map(fn (array $crumb, int $i) => [
                '@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb[0], 'item' => $crumb[1],
            ])->all(),
        ];
    }

    /** The graph as the text of a script tag. */
    public static function graph(array ...$nodes): string
    {
        return json_encode(['@context' => 'https://schema.org', '@graph' => array_values($nodes)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}
