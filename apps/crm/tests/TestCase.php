<?php

namespace Tests;

use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetContext();
    }

    protected function tearDown(): void
    {
        $this->forgetContext();

        parent::tearDown();
    }

    /**
     * Whose company and branch this process thinks it is in.
     *
     * These four memoise for the life of the PHP process, not the request,
     * which is right in a web app - one request is one company - and wrong
     * in a suite, where one process is hundreds of databases. A test that
     * resolved a tenant handed its id to every class that ran after it,
     * against rows that had been rolled back underneath it, and the only
     * evidence was a foreign-key SQLSTATE in a class that had nothing to do
     * with tenancy. Whole files passed alone and failed in the suite.
     *
     * Cleared on both sides on purpose: tearDown covers the ordinary case,
     * setUp covers the test that died before it got there.
     */
    private function forgetContext(): void
    {
        CurrentTenant::forget();
        // Cascades into Modules and PlanAccess, which are cleared again
        // below so that this list reads as what it guarantees rather than
        // as something that depends on another class keeping its habit.
        CurrentShop::forget();
        Modules::forget();
        PlanAccess::forget();
    }
}
