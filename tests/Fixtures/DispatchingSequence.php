<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use Illuminate\Support\Facades\Bus;

class DispatchingSequence extends ScheduledSequence
{
    protected array $offsets = ['now'];

    protected function handle(Occurrence $occurrence): void
    {
        Bus::dispatch(new OccurrenceAwareApplicationJob($occurrence->key));
    }
}
