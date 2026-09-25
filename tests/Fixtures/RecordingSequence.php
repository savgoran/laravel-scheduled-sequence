<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;

class RecordingSequence extends ScheduledSequence
{
    public static array $handled = [];

    protected array $offsets = ['1 day', '3 days'];

    protected function handle(Occurrence $occurrence): void
    {
        self::$handled[] = $occurrence->key;
    }
}
