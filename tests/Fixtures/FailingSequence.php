<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use RuntimeException;

class FailingSequence extends ScheduledSequence
{
    public static array $attemptedKeys = [];

    protected array $offsets = ['now'];

    protected function handle(Occurrence $occurrence): void
    {
        self::$attemptedKeys[] = $occurrence->key;

        throw new RuntimeException('Expected test failure.');
    }
}
