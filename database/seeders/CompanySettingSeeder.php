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
            'company_name' => 'Tiara Softwares',
            'company_title' => 'world pvt ltd',
            'currency' => 'INR',
            'company_email' => 'info@tiarasoftwares.com',
            'phone' => '0141 - 40 50 311',

            // Social Links
            'facebook_url' => 'https://www.facebook.com/softwarestiara/',
            'twitter_url' => 'https://twitter.com/yourpage',
            'linkedin_url' => 'https://www.linkedin.com/company/tiara-softwares-world-private-limited/',
            'instagram_url' => 'https://www.instagram.com/tiarasoftwares/',
            'whatsapp_no' => '+917851066229',

            // URLs
            'product_view_url' => 'https://www.gesa.in',
            'feedback_url' => 'https://www.geqovuw.me',

            // Tax & Registration
            'amount_format' => 'indian',

            // Address
            'address_line_1' => 'S2, Shopping Complex, 3rd Floor, Opp. LBS College,',
            'address_line_2' => 'Tilak Marg',
            'city' => 'Jaipur',
            'zip_code' => '302004',
            'country' => 'India',
            'state' => 'Rajasthan',

            // General
            'date_format' => 'd-M-Y',
            'operation_hours' => '9.30 AM - 6.30 PM',

            // SEO
            'meta_title_home' => 'Tiara Softwares',
            'meta_title_products' => 'Restaurant POS & ERP software',
            'topbar_offer_text' => 'Quia ipsum nihil as',
            'contact_heading' => 'See it running on your own menu',
            'demo_form_title' => 'Request a Demo',
            'contact_card_title' => 'Talk to a restaurant specialist',

            // Mail
            //
            // `log` on purpose. A fresh install that arrives already pointed
            // at a live SMTP host sends its first real message the moment
            // somebody clicks "forgot password" while still setting up. The
            // host and credentials below stay, so the screen is populated and
            // one dropdown turns it on once the operator means it.
            'mail_mailer' => 'log',
            'mail_host' => 'mail.tiarasoftwares.co.in',
            'mail_port' => '587',
            'mail_username' => 'lab@tiarasoftwares.co.in',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'lab@tiarasoftwares.co.in',
            'mail_from_name' => 'Tiara Softwares',

            // Integrations - secrets are deliberately left unset.
            'recaptcha_key_type' => 'v3_invisible',
            'razorpay_currency' => 'INR',
        ];
    }
}
