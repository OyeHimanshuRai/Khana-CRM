<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The public site's identity, read from Company Settings.
 *
 * One array for every public page (landing, policy pages), so the name, logo
 * and contact details come from a single place and change in a single place.
 */
final class SiteBrand
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $name = Setting::get('company_name', config('app.name'));
        $logo = CompanySettings::fileUrl('site_logo');
        $favicon = CompanySettings::fileUrl('favicon');
        $phone = Setting::get('phone');
        $whatsapp = Setting::get('whatsapp_no');

        return [
            'name' => $name,
            'tagline' => Setting::get('company_title'),
            'logo' => $logo,
            // Its own upload, for a dark footer; the header's logo otherwise.
            'footer_logo' => CompanySettings::fileUrl('footer_logo') ?? $logo,
            'favicon' => $favicon ?? asset('favicon.ico'),
            'favicon_type' => self::iconType($favicon),
            'email' => Setting::get('company_email'),
            'phone' => $phone,
            'phone_href' => $phone ? 'tel:'.preg_replace('/[^0-9+]/', '', $phone) : null,
            'whatsapp' => $whatsapp,
            'whatsapp_href' => $whatsapp ? 'https://wa.me/'.preg_replace('/\D+/', '', $whatsapp) : null,

            'address' => collect([
                Setting::get('address_line_1'),
                Setting::get('address_line_2'),
                Setting::get('city'),
                Setting::get('state'),
                Setting::get('zip_code'),
                Setting::get('country'),
            ])->filter()->implode(', '),

            // "Mon - Sat · 10 am - 7 pm", from Settings > General.
            'hours' => collect([Setting::get('operation_days'), Setting::get('operation_hours')])
                ->filter()->implode(' · '),

            /*
             | Only the networks somebody filled in. An empty href renders as a
             | link to the current page, which is worse than no icon.
             */
            'social' => collect([
                'Instagram' => Setting::get('instagram_url'),
                'Facebook' => Setting::get('facebook_url'),
                'X' => Setting::get('twitter_url'),
                'LinkedIn' => Setting::get('linkedin_url'),
            ])->filter()->all(),

            'meta_title' => Setting::get('meta_title_home') ?: $name.' | Restaurant POS & QR Ordering',
            'meta_description' => Setting::get('meta_description_home'),
            'contact_heading' => Setting::get('contact_heading'),
            'contact_description' => Setting::get('contact_description'),
            'demo_video' => Setting::get('hero_demo_video_url'),
        ];
    }

    /**
     * The MIME type for a favicon, from its extension.
     *
     * The same mapping the admin layout uses; null for the shipped .ico.
     */
    private static function iconType(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return match (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/x-icon',
        };
    }
}
