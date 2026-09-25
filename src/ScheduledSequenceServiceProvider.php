<?php

namespace AiSoft\ScheduledSequence;

use AiSoft\ScheduledSequence\Commands\MakeScheduledSequenceCommand;
use AiSoft\ScheduledSequence\Commands\RunScheduledSequencesCommand;
use AiSoft\ScheduledSequence\Services\OccurrenceExecutor;
use AiSoft\ScheduledSequence\Services\OccurrenceGuard;
use AiSoft\ScheduledSequence\Services\OccurrencePublisher;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Register package services, resources, commands, and scheduler integration.
 */
class ScheduledSequenceServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and singleton services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scheduled-sequence.php', 'scheduled-sequence');

        $this->app->singleton(ScheduledSequenceRunner::class);
        $this->app->singleton(OccurrencePublisher::class);
        $this->app->singleton(OccurrenceExecutor::class);
        $this->app->singleton(OccurrenceGuard::class);
    }

    /**
     * Publish package resources and register console behavior.
     */
    public function boot(): void
    {
        $this->publishes(
            [__DIR__.'/../config/scheduled-sequence.php' => config_path('scheduled-sequence.php')],
            'scheduled-sequence-config',
        );
        $this->publishesMigrations(
            [__DIR__.'/../database/migrations' => database_path('migrations')],
            'scheduled-sequence-migrations',
        );
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeScheduledSequenceCommand::class,
                RunScheduledSequencesCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('scheduled-sequence.register_scheduler', true)) {
                return;
            }

            $schedule->command('scheduled-sequence:run')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
