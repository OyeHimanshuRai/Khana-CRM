<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The home is behind sign-in, so a guest is sent to the login screen.
     */
    public function test_a_guest_is_redirected_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }
}
