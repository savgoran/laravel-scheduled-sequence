<?php

namespace AiSoft\ScheduledSequence\Services;

use AiSoft\ScheduledSequence\Jobs\ExecuteScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use Illuminate\Support\Facades\Bus;

/**
 * Publish pending occurrence records to Laravel Queue.
 */
class OccurrencePublisher
{
    /**
     * Publish ready occurrences up to the configured or supplied limit.
     *
     * @return int Number of dispatched occurrence jobs.
     */
    public function publishPending(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('scheduled-sequence.publish_limit', 100));
        $model = config(
            'scheduled-sequence.occurrence_model',
            ScheduledSequenceOccurrence::class,
        );
        $published = 0;

        $ids = $model::query()
            ->publishable()
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck((new $model())->getKeyName())
            ->all();

        foreach ($ids as $id) {
            $job = new ExecuteScheduledSequenceOccurrence($id);
            $connection = config('scheduled-sequence.queue_connection');
            $queue = config('scheduled-sequence.queue');

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }
            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }

            Bus::dispatch($job);
            $published++;

            $model::query()
                ->whereKey($id)
                ->where('status', ScheduledSequenceOccurrence::STATUS_PENDING)
                ->update([
                    'status' => ScheduledSequenceOccurrence::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $published;
    }
}
