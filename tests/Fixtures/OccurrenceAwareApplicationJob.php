<?php

namespace AiSoft\ScheduledSequence\Tests\Fixtures;

use AiSoft\ScheduledSequence\Services\OccurrenceGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

class OccurrenceAwareApplicationJob implements ShouldQueue
{
    /** @var list<string> */
    public static array $effects = [];

    public function __construct(public readonly string $occurrenceKey)
    {
    }

    public function handle(OccurrenceGuard $guard): void
    {
        if (! $guard->allows($this->occurrenceKey)) {
            return;
        }

        self::$effects[] = $this->occurrenceKey;
    }
}
