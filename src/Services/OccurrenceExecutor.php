<?php

namespace AiSoft\ScheduledSequence\Services;

use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Atomically claim and execute durable occurrences.
 */
class OccurrenceExecutor
{
    /**
     * Execute an occurrence when it is current and claimable.
     *
     * @return bool True when the handler completed successfully.
     *
     * @throws Throwable Re-throws handler failures for Laravel Queue retries.
     */
    public function execute(int|string $occurrenceId): bool
    {
        $record = $this->claim($occurrenceId);
        if (! $record) {
            return false;
        }

        $sequence = $record->sequence;
        if (! $sequence) {
            $this->finish($record, ScheduledSequenceOccurrence::STATUS_STALE);

            return false;
        }

        try {
            $sequence->refresh();
            if (
                (int) $sequence->definition_version !== (int) $record->definition_version
                || $sequence->status === ScheduledSequenceModel::STATUS_CANCELLED
            ) {
                $this->finish($record, ScheduledSequenceOccurrence::STATUS_STALE);

                return false;
            }

            $handler = ScheduledSequence::createFromSequence($sequence);
            $occurrence = new Occurrence($record, $sequence);

            if (! $handler->processOccurrence($occurrence)) {
                $this->cancel($record, $sequence);

                return false;
            }

            $this->finish($record, ScheduledSequenceOccurrence::STATUS_SUCCEEDED);

            return true;
        } catch (Throwable $exception) {
            $this->fail($record, $exception);

            throw $exception;
        }
    }

    /**
     * Atomically transition a claimable occurrence to running.
     */
    private function claim(int|string $occurrenceId): ?ScheduledSequenceOccurrence
    {
        $model = config(
            'scheduled-sequence.occurrence_model',
            ScheduledSequenceOccurrence::class,
        );
        $prototype = new $model();

        $id = DB::connection($prototype->getConnectionName())->transaction(
            function () use ($model, $occurrenceId): int|string|null {
                /** @var ScheduledSequenceOccurrence|null $record */
                $record = $model::query()->whereKey($occurrenceId)->lockForUpdate()->first();
                if (! $record || ! in_array($record->status, [
                    ScheduledSequenceOccurrence::STATUS_PENDING,
                    ScheduledSequenceOccurrence::STATUS_PUBLISHED,
                    ScheduledSequenceOccurrence::STATUS_FAILED,
                ], true)) {
                    return null;
                }

                $record->forceFill([
                    'status' => ScheduledSequenceOccurrence::STATUS_RUNNING,
                    'attempts' => (int) $record->attempts + 1,
                    'claimed_at' => now(),
                    'started_at' => now(),
                    'finished_at' => null,
                    'last_error' => null,
                ])->saveQuietly();

                return $record->getKey();
            },
        );

        return $id === null ? null : $model::query()->with('sequence')->find($id);
    }

    /**
     * Cancel the matching sequence definition and occurrence.
     */
    private function cancel(
        ScheduledSequenceOccurrence $occurrence,
        ScheduledSequenceModel $sequence,
    ): void {
        DB::connection($occurrence->getConnectionName())->transaction(
            function () use ($occurrence, $sequence): void {
                $sequence->newQuery()
                    ->whereKey($sequence->getKey())
                    ->where('definition_version', $occurrence->definition_version)
                    ->update([
                        'status' => ScheduledSequenceModel::STATUS_CANCELLED,
                        'end_at' => now(),
                        'next_at' => null,
                        'updated_at' => now(),
                    ]);

                $this->finish($occurrence, ScheduledSequenceOccurrence::STATUS_CANCELLED);
            },
        );
    }

    /**
     * Move a running occurrence to a terminal status.
     */
    private function finish(ScheduledSequenceOccurrence $record, string $status): void
    {
        $record->newQuery()
            ->whereKey($record->getKey())
            ->where('status', ScheduledSequenceOccurrence::STATUS_RUNNING)
            ->update([
                'status' => $status,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Persist a bounded failure summary for a running occurrence.
     */
    private function fail(ScheduledSequenceOccurrence $record, Throwable $exception): void
    {
        $error = $exception::class;
        if (config('scheduled-sequence.store_exception_messages', false)) {
            $error .= ': '.$exception->getMessage();
        }

        $record->newQuery()
            ->whereKey($record->getKey())
            ->where('status', ScheduledSequenceOccurrence::STATUS_RUNNING)
            ->update([
                'status' => ScheduledSequenceOccurrence::STATUS_FAILED,
                'finished_at' => now(),
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
