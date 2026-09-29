<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\ScheduledSequence;

class DailySequence extends ScheduledSequence
{
    protected array $offsets = ['1 day'];

    protected bool $repeatSequence = true;
}
