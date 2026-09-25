<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;

class GuardedSequence extends ScheduledSequence
{
    protected array $offsets = ['now'];

    protected function shouldContinue(Occurrence $occurrence): bool
    {
        return false;
    }
}
