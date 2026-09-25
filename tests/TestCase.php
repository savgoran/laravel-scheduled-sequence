<?php

namespace AiSoft\ScheduledSequence\Tests;

use AiSoft\ScheduledSequence\ScheduledSequenceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ScheduledSequenceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $namespace = new ReflectionProperty($app, 'namespace');
        $namespace->setAccessible(true);
        $namespace->setValue($app, 'Workbench\\App\\');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('scheduled-sequence.timezone', 'UTC');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
