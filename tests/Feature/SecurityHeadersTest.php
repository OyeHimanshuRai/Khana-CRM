<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_payment_popups_keep_their_opener(): void
    {
        $this->get('/login')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_razorpay_checkout_frame_may_use_the_payment_sheet(): void
    {
        $policy = $this->get('/login')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('payment=(self "https://api.razorpay.com")', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
    }
}
