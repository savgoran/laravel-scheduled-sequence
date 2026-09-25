<?php

namespace AiSoft\ScheduledSequence\Services;

use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinate due-work materialization, recovery, and retention.
 */
class ScheduledSequenceRunner
{
    /**
     * Atomically materialize occurrences for due active sequences.
     *
     * @return array<int, int|string>
     */
    public function materializeDue(?Carbon $currentTime = null, ?int $limit = null): array
    {
        $currentTime ??= Carbon::now(config('scheduled-sequence.timezone', 'UTC'));
        $limit ??= max(1, (int) config('scheduled-sequence.run_limit', 100));
        $model = config('scheduled-sequence.model', ScheduledSequenceModel::class);

        $ids = $model::query()
            ->where('status', ScheduledSequenceModel::STATUS_ACTIVE)
            ->where('next_at', '<=', $currentTime)
            ->orderBy('next_at')
            ->limit($limit)
            ->pluck((new $model())->getKeyName())
            ->all();

        $occurrenceIds = [];
        foreach ($ids as $id) {
            array_push($occurrenceIds, ...$this->materializeSequence($id, $currentTime));
        }

        return $occurrenceIds;
    }

    /**
     * Materialize due occurrences for one locked sequence record.
     *
     * @return array<int, int|string>
     */
    public function materializeSequence(int|string $id, ?Carbon $currentTime = null): array
    {
        $currentTime ??= Carbon::now(config('scheduled-sequence.timezone', 'UTC'));
        $model = config('scheduled-sequence.model', ScheduledSequenceModel::class);
        $prototype = new $model();

        return DB::connection($prototype->getConnectionName())->transaction(
            function () use ($model, $id, $currentTime): array {
                /** @var ScheduledSequenceModel|null $record */
                $record = $model::query()->whereKey($id)->lockForUpdate()->first();

                if (
                    ! $record
                    || $record->status !== ScheduledSequenceModel::STATUS_ACTIVE
                    || ! $record->next_at
                    || Carbon::instance($record->next_at)->greaterThan($currentTime)
                ) {
                    return [];
                }

                return ScheduledSequence::createFromSequence($record)
                    ->materializeDueOccurrences($currentTime);
            },
        );
    }

    /**
     * Return abandoned running claims to the pending state.
     *
     * @return int Number of recovered occurrences.
     */
    public function recoverAbandonedOccurrences(?Carbon $currentTime = null): int
    {
        $currentTime ??= Carbon::now(config('scheduled-sequence.timezone', 'UTC'));
        $occurrenceModel = config(
            'scheduled-sequence.occurrence_model',
            ScheduledSequenceOccurrence::class,
        );
        $cutoff = $currentTime->clone()->subSeconds(
            max(1, (int) config('scheduled-sequence.abandoned_after_seconds', 900)),
        );

        return $occurrenceModel::query()
            ->where('status', ScheduledSequenceOccurrence::STATUS_RUNNING)
            ->where('claimed_at', '<=', $cutoff)
            ->update([
                'status' => ScheduledSequenceOccurrence::STATUS_PENDING,
                'available_at' => $currentTime,
                'claimed_at' => null,
                'started_at' => null,
                'updated_at' => $currentTime,
            ]);
    }

    /**
     * Delete expired terminal sequences that are not permanent audit records.
     *
     * @return int Number of pruned sequences.
     */
    public function pruneTerminalSequences(?Carbon $currentTime = null): int
    {
        $currentTime ??= Carbon::now(config('scheduled-sequence.timezone', 'UTC'));
        $retention = max(0, (int) config('scheduled-sequence.terminal_retention_seconds', 604800));
        $cutoff = $currentTime->clone()->subSeconds($retention);
        $model = config('scheduled-sequence.model', ScheduledSequenceModel::class);
        $pruned = 0;

        $model::query()
            ->whereIn('status', [
                ScheduledSequenceModel::STATUS_COMPLETED,
                ScheduledSequenceModel::STATUS_CANCELLED,
            ])
            ->where('end_at', '<=', $cutoff)
            ->whereDoesntHave('occurrences', function ($query): void {
                $query->whereIn('status', [
                    ScheduledSequenceOccurrence::STATUS_PENDING,
                    ScheduledSequenceOccurrence::STATUS_PUBLISHED,
                    ScheduledSequenceOccurrence::STATUS_RUNNING,
                    ScheduledSequenceOccurrence::STATUS_FAILED,
                ]);
            })
            ->orderBy((new $model())->getKeyName())
            ->eachById(function (ScheduledSequenceModel $record) use (&$pruned): void {
                try {
                    $handler = ScheduledSequence::createFromSequence($record);
                    if (! $handler->remembersPermanently()) {
                        $record->delete();
                        $pruned++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }, 100, (new $model())->getKeyName());

        return $pruned;
    }
}
