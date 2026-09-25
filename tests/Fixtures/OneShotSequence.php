<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\ScheduledSequence;

class OneShotSequence extends ScheduledSequence
{
    protected array $offsets = ['now'];
}
