<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Khana\Kit\Tests\TestCase;

class NoMountPathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://erp.khana.example');
    }

    public function test_an_app_on_its_own_origin_is_left_alone(): void
    {
        Route::get('login', fn () => 'ok')->name('login');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame('http://localhost/login', route('login'));
    }
}
