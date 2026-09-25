<?php

namespace AiSoft\ScheduledSequence;

use Illuminate\Support\ServiceProvider;

final class ScheduledSequenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Package bindings will be added as the public contract stabilizes.
    }

    public function boot(): void
    {
        // Commands, migrations and scheduler integration will be registered here.
    }
}
