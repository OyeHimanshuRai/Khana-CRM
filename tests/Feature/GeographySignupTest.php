<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Country > State > City and the mobile number on the sign-up form: the lists
 * come from the database, the calling code follows the country, and a number
 * that is not valid for the chosen country is refused.
 */
class GeographySignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        Mail::fake();
    }

    private function country(string $iso): Country
    {
        return Country::query()->where('iso_code', $iso)->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '9876543210', 'password' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ], $overrides);
    }

    /* --------------------------------------------------------------- data */

    public function test_the_countries_carry_their_calling_codes_and_number_lengths(): void
    {
        $this->assertSame('91', $this->country('IN')->phone_code);
        $this->assertSame('1', $this->country('US')->phone_code);
        $this->assertSame('44', $this->country('GB')->phone_code);
        $this->assertSame('61', $this->country('AU')->phone_code);
        $this->assertSame(10, $this->country('IN')->min_length);
        $this->assertSame(10, $this->country('IN')->max_length);
        $this->assertGreaterThan(240, Country::query()->count());
    }

    public function test_states_belong_to_a_country_and_cities_to_a_state(): void
    {
        $maharashtra = State::query()->where('country_id', $this->country('IN')->id)->where('state_name', 'Maharashtra')->firstOrFail();

        $this->assertTrue($maharashtra->country->is($this->country('IN')));
        $this->assertTrue($maharashtra->cities()->where('city_name', 'Pune')->exists());
        $this->assertTrue(City::query()->where('city_name', 'Pune')->firstOrFail()->state->is($maharashtra));
    }

    /* ---------------------------------------------------------------- api */

    public function test_the_lists_load_as_json_one_level_at_a_time(): void
    {
        $india = $this->country('IN');

        $this->getJson(route('geo.countries'))->assertOk()->assertJsonPath('success', true)
            ->assertJsonFragment(['iso_code' => 'IN', 'phone_code' => '91']);

        $states = $this->getJson(route('geo.states', $india->id))->assertOk()->assertJsonFragment(['state_name' => 'Maharashtra']);
        $this->assertGreaterThan(30, count($states->json('data')));

        $maharashtra = State::query()->where('country_id', $india->id)->where('state_name', 'Maharashtra')->firstOrFail();
        $this->getJson(route('geo.cities', $maharashtra->id))->assertOk()->assertJsonFragment(['city_name' => 'Pune']);

        // A state of another country is never in the list.
        $this->getJson(route('geo.states', $this->country('US')->id))->assertOk()->assertJsonMissing(['state_name' => 'Maharashtra']);
    }

    public function test_an_unknown_country_or_state_is_a_json_404_and_not_a_server_error(): void
    {
        $this->getJson('/geo/countries/999999/states')->assertStatus(404)->assertJsonPath('success', false);
        $this->getJson('/geo/states/999999/cities')->assertStatus(404)->assertJsonPath('success', false);
        $this->getJson('/geo/countries/abc/states')->assertStatus(404);
    }

    public function test_an_injection_attempt_in_the_route_is_just_not_found(): void
    {
        $this->getJson('/geo/countries/1%20OR%201=1/states')->assertStatus(404);
        $this->getJson("/geo/states/1;DROP%20TABLE%20users/cities")->assertStatus(404);
        $this->assertGreaterThan(0, Country::query()->count());
    }

    /* ------------------------------------------------------------ the form */

    public function test_the_form_lists_the_countries_from_the_database_with_india_chosen(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('name="country_id"', false)
            ->assertSee('name="state_id"', false)
            ->assertSee('name="city_id"', false)
            ->assertSee('United States')
            ->assertSee('data-code="91"', false)
            ->assertSee('assets/vendor/libphonenumber-js.max.js', false);
    }

    /* -------------------------------------------------------------- mobile */

    public function test_a_valid_number_for_each_country_is_accepted(): void
    {
        foreach ([['IN', '9876543210', '+91'], ['US', '2025550123', '+1'], ['GB', '7400123456', '+44'], ['AU', '412345678', '+61']] as [$iso, $number, $code]) {
            $parsed = MobileNumber::parse($number, $this->country($iso));

            $this->assertNotNull($parsed, $iso);
            $this->assertSame($code, $parsed['code']);
            $this->assertSame($code.' '.$parsed['national'], $parsed['stored']);
        }
    }

    public function test_numbers_that_are_not_valid_for_the_country_are_refused(): void
    {
        $in = $this->country('IN');

        $this->assertNull(MobileNumber::parse('12345', $in), 'too short');
        $this->assertNull(MobileNumber::parse('98765432101234', $in), 'too long');
        $this->assertNull(MobileNumber::parse('1234567890', $in), 'a landline is not a mobile');
        $this->assertNull(MobileNumber::parse('abcdefghij', $in), 'letters');
        $this->assertNull(MobileNumber::parse('', $in), 'empty');
        $this->assertNull(MobileNumber::parse('2025550123', $in), 'a US number is not an Indian one');
        $this->assertNull(MobileNumber::parse('+1 202 555 0123', $in), 'another country\'s code');
        $this->assertNotNull(MobileNumber::parse('+91 98765 43210', $in), 'its own code is fine');
    }

    /* ------------------------------------------------------------- sign-up */

    public function test_signing_up_in_another_country_saves_its_code_number_and_place(): void
    {
        $gb = $this->country('GB');
        $england = State::query()->where('country_id', $gb->id)->where('state_name', 'England')->firstOrFail();
        $city = City::query()->where('state_id', $england->id)->orderBy('city_name')->firstOrFail();

        $this->postJson('/register', $this->payload([
            'country_id' => $gb->id, 'state_id' => $england->id, 'city_id' => $city->id, 'mobile' => '07400 123456',
        ]))->assertOk()->assertJsonPath('success', true);

        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $this->assertSame('+44 7400123456', $owner->mobile);
        $this->assertSame('+44', $owner->mobile_country_code);

        $tenant = $owner->tenant;
        $this->assertSame('United Kingdom', $tenant->country);
        $this->assertSame('England', $tenant->state);
        $this->assertSame($city->city_name, $tenant->city);
        $this->assertSame('+44 7400123456', $tenant->phone);
    }

    public function test_a_sign_up_that_names_no_country_is_indian_as_before(): void
    {
        $this->postJson('/register', $this->payload())->assertOk();

        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $this->assertSame('+91 9876543210', $owner->mobile);
        $this->assertSame('+91', $owner->mobile_country_code);
        $this->assertSame('India', $owner->tenant->country);
    }

    public function test_an_invalid_number_is_refused_by_the_server_and_nothing_is_created(): void
    {
        $this->postJson('/register', $this->payload(['country_id' => $this->country('US')->id, 'mobile' => '98765']))
            ->assertStatus(422)->assertJsonValidationErrors(['mobile' => 'Enter a valid United States mobile number.']);

        $this->postJson('/register', $this->payload(['mobile' => '5555555555']))->assertStatus(422)->assertJsonValidationErrors('mobile');

        $this->assertDatabaseMissing('users', ['email' => 'asha@spicegarden.test']);
    }

    public function test_a_state_of_another_country_or_a_city_of_another_state_is_refused(): void
    {
        $india = $this->country('IN');
        $us = $this->country('US');
        $texas = State::query()->where('country_id', $us->id)->where('state_name', 'Texas')->firstOrFail();
        $maharashtra = State::query()->where('country_id', $india->id)->where('state_name', 'Maharashtra')->firstOrFail();
        $gujarat = State::query()->where('country_id', $india->id)->where('state_name', 'Gujarat')->firstOrFail();
        $pune = City::query()->where('state_id', $maharashtra->id)->where('city_name', 'Pune')->firstOrFail();

        $this->postJson('/register', $this->payload(['country_id' => $india->id, 'state_id' => $texas->id, 'city_id' => $pune->id]))
            ->assertStatus(422)->assertJsonValidationErrors('state_id');

        $this->postJson('/register', $this->payload(['country_id' => $india->id, 'state_id' => $gujarat->id, 'city_id' => $pune->id]))
            ->assertStatus(422)->assertJsonValidationErrors('city_id');

        $this->postJson('/register', $this->payload(['country_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('country_id');

        $this->assertDatabaseMissing('users', ['email' => 'asha@spicegarden.test']);
    }

    public function test_a_state_needs_a_city_to_go_with_it(): void
    {
        $maharashtra = State::query()->where('state_name', 'Maharashtra')->firstOrFail();

        $this->postJson('/register', $this->payload(['state_id' => $maharashtra->id]))
            ->assertStatus(422)->assertJsonValidationErrors('city_id');
    }

    public function test_a_state_with_no_cities_listed_can_be_chosen_without_one(): void
    {
        $germany = $this->country('DE');
        $state = State::query()->where('country_id', $germany->id)->firstOrFail();
        $this->assertFalse(City::query()->where('state_id', $state->id)->exists());

        $this->postJson('/register', $this->payload(['country_id' => $germany->id, 'state_id' => $state->id, 'mobile' => '15112345678']))
            ->assertOk();

        $this->assertSame($state->state_name, User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail()->tenant->state);
    }
}
