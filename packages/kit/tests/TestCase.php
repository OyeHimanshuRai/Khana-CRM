<?php

namespace Khana\Kit\Tests;

use Khana\Kit\KitServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [KitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('khana.service', 'crm');
        $app['config']->set('khana.contracts_path', self::contractsPath());
    }

    protected static function contractsPath(): string
    {
        return dirname(__DIR__, 3).'/contracts';
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixture(string $type, int $version = 1): array
    {
        $path = self::contractsPath()."/fixtures/events/{$type}.v{$version}.json";

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
