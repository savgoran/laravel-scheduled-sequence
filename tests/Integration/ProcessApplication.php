<?php

namespace AiSoft\ScheduledSequence\Tests\Integration;

use AiSoft\ScheduledSequence\ScheduledSequenceServiceProvider;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ReliabilityEnvironment;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;

final class ProcessApplication extends TestCase
{
    /**
     * Boot the Testbench application for a standalone child process.
     */
    public function bootApplication(): Application
    {
        parent::setUp();

        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [ScheduledSequenceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        ReliabilityEnvironment::configure($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        // The controlling PHPUnit process owns schema creation and cleanup.
    }
}
