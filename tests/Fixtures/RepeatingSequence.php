<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\ScheduledSequence;

class RepeatingSequence extends ScheduledSequence
{
    protected array $offsets = ['1 day', '3 days'];

    protected ?string $repeatEvery = '3 days';
}
