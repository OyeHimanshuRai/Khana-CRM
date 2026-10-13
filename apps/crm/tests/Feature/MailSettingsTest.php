<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The outgoing mail settings saved under Settings > General.
 *
 * These lived beside the welcome-email tests until the welcome email went to
 * Khana Accounts with the rest of signing people up; what CRM still sends -
 * receipts, reminders, campaigns, template tests - goes out through them.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $pinnedMailer = null;

    // phpunit.xml pins MAIL_MAILER=array, which rightly wins over the screen; unpin it so the screen decides.
    protected function setUp(): void
    {
        parent::setUp();

        $this->pinnedMailer = env('MAIL_MAILER');
        putenv('MAIL_MAILER');
        unset($_ENV['MAIL_MAILER'], $_SERVER['MAIL_MAILER']);
    }

    protected function tearDown(): void
    {
        if ($this->pinnedMailer !== null) {
            putenv('MAIL_MAILER='.$this->pinnedMailer);
            $_ENV['MAIL_MAILER'] = $_SERVER['MAIL_MAILER'] = $this->pinnedMailer;
        }

        parent::tearDown();
    }

    public function test_the_saved_mail_settings_are_applied(): void
    {
        Setting::put([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '2525',
            'mail_username' => 'someone@example.test',
            'mail_password' => 'a-secret',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.test',
            'mail_from_name' => 'Example Co',
        ]);

        MailConfigurator::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('noreply@example.test', config('mail.from.address'));
        // Laravel 11+ calls it scheme, and "tls" means the plain smtp scheme.
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    public function test_ssl_maps_to_the_smtps_scheme(): void
    {
        Setting::put(['mail_mailer' => 'smtp', 'mail_encryption' => 'ssl']);

        MailConfigurator::apply();

        // The old "ssl" wording means implicit TLS, which Laravel spells smtps.
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_a_blank_setting_leaves_the_env_value_alone(): void
    {
        config(['mail.mailers.smtp.host' => 'from-env.example']);

        Setting::put(['mail_mailer' => 'smtp', 'mail_host' => '']);

        MailConfigurator::apply();

        $this->assertSame('from-env.example', config('mail.mailers.smtp.host'));
    }
}
