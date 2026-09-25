<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;
use AiSoft\ScheduledSequence\ScheduledSequence;

class SkipSequence extends ScheduledSequence
{
    protected array $offsets = ['1 day', '3 days', '7 days'];

    protected ?string $catchUpPolicy = CatchUpPolicy::SKIP;
}
