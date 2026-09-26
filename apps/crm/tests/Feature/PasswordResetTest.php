<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "I have forgotten my password."
 *
 * The screen that had to exist once restaurants started opening their own
 * accounts: an owner who chose their own password has no administrator to ask
 * for a fresh welcome link.
 *
 * Two things are worth holding here, and only one of them is the happy path:
 *
 *   - the link that arrives actually sets a password and signs them in;
 *   - the form cannot be used to find out which addresses have accounts,
 *     which is what an "unknown email" message would turn it into.
 *
 * Nothing in this file sends a real email: Mail::fake() intercepts every send.
 *
 * The assertions are `assertQueued` rather than `assertSent`, and that is not
 * a style choice - PasswordResetMail implements ShouldQueue, so `Mail::send()`
 * hands it to the queue and the fake records it there. `assertSent` on a
 * queued mailable passes for the wrong reason: it never matches.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        Mail::fake();
    }

    private function owner(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /* ---------------------------------------------------------- the form */

    public function test_the_form_is_public(): void
    {
        $this->assertGuest();

        $this->get('/admin/password/forgot')
            ->assertOk()
            ->assertSee('Forgotten your password?');
    }

    public function test_the_login_screen_links_to_it(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(route('admin.password.request'), false);
    }

    /* -------------------------------------------------------- the e-mail */

    public function test_a_known_address_is_sent_a_link(): void
    {
        $owner = $this->owner();

        $this->post('/admin/password/forgot', ['email' => $owner->email])
            ->assertRedirect(route('admin.password.request'))
            ->assertSessionHas('status');

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && str_contains($mail->resetUrl, '/admin/password/set/');
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
    }

    /**
     * The whole point of the feature: the link works.
     *
     * Reset through the screen the emailed link actually points at, rather
     * than by calling the broker - the two brokers share one table and it
     * would be easy to ship a token the consuming screen rejects.
     */
    public function test_the_emailed_link_sets_a_new_password(): void
    {
        $owner = $this->owner();

        $this->post('/admin/password/forgot', ['email' => $owner->email]);

        $token = null;

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token) {
            preg_match('#/admin/password/set/([^?]+)#', $mail->resetUrl, $matches);
            $token = $matches[1] ?? null;

            return true;
        });

        $this->assertNotNull($token, 'The email carried no token.');

        $this->get('/admin/password/set/'.$token)->assertOk();

        $this->post('/admin/password/set', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a-brand-new-password', $owner->fresh()->password));

        // One-time: the row is consumed, so the same link cannot be replayed.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
    }

    /* --------------------------------------------------------- refusals */

    /**
     * An address with no account gets the same sentence and no email.
     *
     * A form that said "no account with that email" would answer, one request
     * at a time, which restaurants are customers.
     */
    public function test_an_unknown_address_is_answered_identically(): void
    {
        $known = $this->post('/admin/password/forgot', ['email' => $this->owner()->email]);
        $unknown = $this->post('/admin/password/forgot', ['email' => 'nobody@example.test']);

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );

        Mail::assertQueued(PasswordResetMail::class, 1);
    }

    public function test_a_deactivated_account_is_sent_nothing(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['is_active' => false])->save();

        $this->post('/admin/password/forgot', ['email' => $owner->email])
            ->assertSessionHas('status');

        Mail::assertNothingQueued();
    }

    public function test_it_refuses_something_that_is_not_an_address(): void
    {
        $this->post('/admin/password/forgot', ['email' => 'not-an-address'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingQueued();
    }
}
