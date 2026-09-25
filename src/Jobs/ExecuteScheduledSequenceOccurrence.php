<?php

namespace AiSoft\ScheduledSequence\Jobs;

use AiSoft\ScheduledSequence\Services\OccurrenceExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Execute one durable scheduled-sequence occurrence.
 */
class ExecuteScheduledSequenceOccurrence implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum number of queue attempts.
     */
    public int $tries;

    /**
     * Queue retry delay or backoff sequence in seconds.
     *
     * @var int|array<int, int>
     */
    public array|int $backoff;

    /**
     * Create a queued occurrence job.
     */
    public function __construct(public readonly int|string $occurrenceId)
    {
        $this->tries = max(1, (int) config('scheduled-sequence.queue_tries', 3));
        $this->backoff = config('scheduled-sequence.queue_backoff', [60, 300, 900]);
    }

    /**
     * Execute the referenced occurrence.
     */
    public function handle(OccurrenceExecutor $executor): void
    {
        $executor->execute($this->occurrenceId);
    }

    /**
     * Report a terminal queue failure.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }
    }
}
