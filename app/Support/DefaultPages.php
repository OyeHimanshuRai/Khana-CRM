<?php

namespace App\Support;

use App\Models\Page;

/**
 * The three pages a business site is expected to carry, written once.
 *
 * Added only when their address is free, and never overwritten: from then on the
 * platform owner edits them under Site Pages. The text is in database/data/pages.
 */
final class DefaultPages
{
    /** @return int how many pages were created */
    public static function publish(): int
    {
        $pages = [
            [
                'slug' => 'terms-and-conditions',
                'title' => 'Terms and Conditions',
                'meta_title' => 'Terms and Conditions | RestroOne Restaurant POS & Billing Software',
                'meta_description' => 'The terms for using RestroOne restaurant POS: accounts, free trial, plans and GST, your data, acceptable use, termination and cancellation.',
                'meta_keywords' => 'RestroOne terms and conditions, restaurant software terms, POS software agreement, subscription terms, termination conditions, restaurant billing software India',
                'sort_order' => 1,
            ],
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'meta_title' => 'Privacy Policy | RestroOne Restaurant POS & Billing Software',
                'meta_description' => 'How RestroOne collects, uses and protects your business and customer data: what we collect, who we share it with, cookies, security and your rights.',
                'meta_keywords' => 'RestroOne privacy policy, restaurant software privacy, data protection, restaurant POS data security, cookies policy',
                'sort_order' => 2,
            ],
            [
                'slug' => 'refund-and-cancellation-policy',
                'title' => 'Refund and Cancellation Policy',
                'meta_title' => 'Refund & Cancellation Policy | RestroOne Restaurant POS',
                'meta_description' => 'How to cancel a RestroOne plan and when refunds are given: free trial, first-payment refund within 7 days, upgrades and how refunds are paid.',
                'meta_keywords' => 'RestroOne refund policy, cancellation policy, restaurant software refund, subscription cancellation, free trial',
                'sort_order' => 3,
            ],
        ];

        $made = 0;

        foreach ($pages as $page) {
            if (Page::query()->where('slug', $page['slug'])->exists()) {
                continue;
            }

            Page::query()->create($page + [
                'body' => trim((string) file_get_contents(database_path('data/pages/'.$page['slug'].'.md'))),
                'is_active' => true,
                'show_in_footer' => true,
            ]);

            $made++;
        }

        return $made;
    }
}
