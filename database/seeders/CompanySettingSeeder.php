<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\CompanySettings;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    /**
     * Seed the company settings.
     *
     * Idempotent and non-destructive: a key that already has a value is left
     * alone, so re-running `db:seed` never overwrites what an operator has
     * since edited on the screen.
     */
    public function run(): void
    {
        $stored = Setting::map();

        $values = collect($this->defaults())
            ->reject(fn ($value, string $key) => filled($stored[$key] ?? null))
            ->all();

        // Any field in the schema with no default and no stored value is
        // written as null, so the settings table mirrors the config.
        foreach (array_keys(CompanySettings::fields()) as $key) {
            if (! array_key_exists($key, $stored) && ! array_key_exists($key, $values)) {
                $values[$key] = null;
            }
        }

        if ($values !== []) {
            Setting::put($values);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function defaults(): array
    {
        return [
            // Company Information
            'company_name' => 'RestroOne',
            'company_title' => '',
            'currency' => 'INR',
            'company_email' => '',
            'phone' => '',

            // Social Links
            'facebook_url' => '',
            'twitter_url' => '',
            'linkedin_url' => '',
            'instagram_url' => '',
            'whatsapp_no' => '',

            // URLs
            'product_view_url' => '',
            'feedback_url' => '',

            // Tax & Registration
            'amount_format' => 'indian',

            // Address
            'address_line_1' => '',
            'address_line_2' => '',
            'city' => '',
            'zip_code' => '',
            'country' => 'India',
            'state' => '',

            // General
            'date_format' => 'd-M-Y',
            'operation_hours' => '',

            // SEO
            'meta_title_home' => 'RestroOne',
            'meta_title_products' => 'Restaurant POS software',
            'topbar_offer_text' => '',
            'contact_heading' => '',
            'demo_form_title' => 'Contact Support',
            'contact_card_title' => 'Talk to a restaurant software specialist',

            // Mail
            'mail_mailer' => 'smtp',
            'mail_host' => '',
            'mail_port' => '587',
            'mail_username' => '',
            'mail_encryption' => 'tls',
            'mail_from_address' => '',
            'mail_from_name' => 'RestroOne',

            // Integrations - secrets are deliberately left unset.
            'recaptcha_key_type' => 'v3_invisible',
            'razorpay_currency' => 'INR',
        ];
    }
}
