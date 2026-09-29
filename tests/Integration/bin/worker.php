<?php

use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Services\OccurrencePublisher;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use AiSoft\ScheduledSequence\Tests\Integration\ProcessApplication;
use AiSoft\ScheduledSequence\Tests\Integration\Support\BarrierDatabaseConnector;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ProcessBarrier;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $action] = $argv + [null, null];

if (! is_string($action)) {
    fwrite(STDERR, "A reliability worker action is required.\n");
    exit(2);
}

$harness = new ProcessApplication('runTest');
$app = $harness->bootApplication();

try {
    if ($action === 'materialize-race') {
        [$sequenceId, $directory, $workerName] = array_slice($argv, 2) + [null, null, null];
        $barrier = new ProcessBarrier((string) $directory);
        $sequence = ScheduledSequenceModel::query()->findOrFail($sequenceId);

        if ($sequence->status !== ScheduledSequenceModel::STATUS_ACTIVE || ! $sequence->next_at) {
            throw new RuntimeException('The shared sequence is not due and active.');
        }

        $barrier->signal("ready-{$workerName}", ['sequence_id' => $sequence->getKey()]);
        $barrier->waitForRelease('race');
        $ids = $app->make(ScheduledSequenceRunner::class)->materializeSequence($sequence->getKey());
        $barrier->signal("result-{$workerName}", ['occurrence_ids' => $ids]);
    } elseif ($action === 'materialize-and-pause') {
        [$sequenceId, $directory] = array_slice($argv, 2) + [null, null];
        $barrier = new ProcessBarrier((string) $directory);
        $ids = $app->make(ScheduledSequenceRunner::class)->materializeSequence($sequenceId);
        $occurrence = ScheduledSequenceOccurrence::query()->findOrFail($ids[0]);

        $barrier->signal('occurrence-committed', [
            'occurrence_id' => $occurrence->getKey(),
            'occurrence_key' => $occurrence->occurrence_key,
        ]);
        $barrier->waitForRelease('occurrence-committed');
    } elseif ($action === 'publish-and-pause') {
        [$directory] = array_slice($argv, 2) + [null];
        $barrier = new ProcessBarrier((string) $directory);
        $app['queue']->addConnector(
            'barrier-database',
            fn () => new BarrierDatabaseConnector($app['db'], $barrier),
        );
        $app['config']->set('scheduled-sequence.queue_connection', 'barrier');
        $app->make(OccurrencePublisher::class)->publishPending();
    } elseif ($action === 'work') {
        $effectBarrier = $argv[2] ?? null;
        if (is_string($effectBarrier) && $effectBarrier !== '') {
            putenv("RELIABILITY_EFFECT_BARRIER={$effectBarrier}");
        }

        $exitCode = $app->make(Kernel::class)->call('queue:work', [
            'connection' => 'reliability',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ]);

        if ($exitCode !== 0) {
            fwrite(STDERR, $app->make(Kernel::class)->output());
            exit($exitCode);
        }
    } else {
        throw new RuntimeException("Unknown reliability worker action [{$action}].");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString());
    exit(1);
}
