<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Support;

use Illuminate\Queue\DatabaseQueue;

class BarrierDatabaseQueue extends DatabaseQueue
{
    public function __construct(
        $database,
        string $table,
        string $default,
        int $retryAfter,
        bool $dispatchAfterCommit,
        private readonly ProcessBarrier $barrier,
    ) {
        parent::__construct($database, $table, $default, $retryAfter, $dispatchAfterCommit);
    }

    /**
     * Persist the job, then pause before returning success to the publisher.
     */
    public function push($job, $data = '', $queue = null)
    {
        $id = parent::push($job, $data, $queue);

        $this->barrier->signal('queue-accepted', ['job_id' => $id]);
        $this->barrier->waitForRelease('queue-accepted');

        return $id;
    }
}
