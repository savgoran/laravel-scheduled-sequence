<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Support;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

class BarrierDatabaseConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ProcessBarrier $barrier,
    ) {
    }

    /**
     * Establish the test database queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config)
    {
        return new BarrierDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? false,
            $this->barrier,
        );
    }
}
