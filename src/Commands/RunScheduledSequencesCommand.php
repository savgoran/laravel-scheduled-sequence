<?php

namespace AiSoft\ScheduledSequence\Commands;

use AiSoft\ScheduledSequence\Services\OccurrencePublisher;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recover, materialize, publish, and prune scheduled-sequence records.
 */
class RunScheduledSequencesCommand extends Command
{
    /** @var string */
    protected $signature = 'scheduled-sequence:run {--limit=} {--publish-limit=}';

    /** @var string */
    protected $description = 'Materialize and publish due scheduled sequence occurrences';

    /**
     * Execute one runner cycle.
     */
    public function handle(
        ScheduledSequenceRunner $runner,
        OccurrencePublisher $publisher,
    ): int {
        try {
            $recovered = $runner->recoverAbandonedOccurrences();
            $occurrences = $runner->materializeDue(
                limit: $this->option('limit') === null
                    ? null
                    : max(1, (int) $this->option('limit')),
            );
            $published = $publisher->publishPending(
                $this->option('publish-limit') === null
                    ? null
                    : max(1, (int) $this->option('publish-limit')),
            );
            $pruned = $runner->pruneTerminalSequences();

            $this->components->info(sprintf(
                'Materialized %d, published %d, recovered %d, and pruned %d scheduled sequence record(s).',
                count($occurrences),
                $published,
                $recovered,
                $pruned,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error("Scheduled sequence runner failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }
}
