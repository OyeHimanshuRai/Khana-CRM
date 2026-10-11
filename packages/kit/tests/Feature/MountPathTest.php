<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Khana\Kit\Tests\TestCase;

class MountPathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://khana.example/erp');
    }

    public function test_links_carry_the_prefix_the_app_is_mounted_under(): void
    {
        Route::get('login', fn () => 'ok')->name('login');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame('https://khana.example/erp/login', route('login'));
        $this->assertSame('https://khana.example/erp/x', url('/x'));
        $this->assertSame('https://khana.example/erp/build/app.css', asset('build/app.css'));
    }
}
