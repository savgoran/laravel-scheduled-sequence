<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\ScheduledSequence;

class RememberedSequence extends ScheduledSequence
{
    protected array $offsets = ['now'];

    protected bool $rememberPermanently = true;
}
