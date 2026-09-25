<?php

namespace AiSoft\ScheduledSequence\Services;

use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;

/**
 * Revalidate delayed application work against current sequence state.
 */
class OccurrenceGuard
{
    /**
     * Determine whether work associated with an occurrence may continue.
     */
    public function allows(string $occurrenceKey, bool $checkBusinessCondition = true): bool
    {
        $model = config(
            'scheduled-sequence.occurrence_model',
            ScheduledSequenceOccurrence::class,
        );
        /** @var ScheduledSequenceOccurrence|null $record */
        $record = $model::query()
            ->with('sequence')
            ->where('occurrence_key', $occurrenceKey)
            ->first();
        $sequence = $record?->sequence;

        if (
            ! $record
            || ! $sequence
            || (int) $sequence->definition_version !== (int) $record->definition_version
            || $sequence->status === ScheduledSequenceModel::STATUS_CANCELLED
            || in_array($record->status, [
                ScheduledSequenceOccurrence::STATUS_STALE,
                ScheduledSequenceOccurrence::STATUS_CANCELLED,
            ], true)
        ) {
            return false;
        }

        if (! $checkBusinessCondition) {
            return true;
        }

        return ScheduledSequence::createFromSequence($sequence)
            ->canContinue(new Occurrence($record, $sequence));
    }
}
