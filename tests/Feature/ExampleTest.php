<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /*
     | Migrated but not seeded, on purpose.
     |
     | Without this the landing page went looking for the settings table on a
     | database that had none and answered 500 - the stock scaffold test,
     | never adapted to an app that reads its own configuration. An empty
     | schema is also the more useful question: the front door has to open on
     | a fresh install, before anybody has typed in a plan or a testimonial.
     */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | APP_URL carries the /lvvyome_test sub-path this install is served
         | from, which would prefix every test request and miss the routes
         | entirely. Every other test in the suite does the same.
         */
        URL::forceRootUrl('http://localhost');
    }

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
