<?php

namespace AiSoft\ScheduledSequence;

use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use Carbon\CarbonImmutable;

/**
 * Immutable application-facing view of a durable occurrence.
 */
final class Occurrence
{
    /** Occurrence record primary key. */
    public readonly int|string $id;

    /** Owning sequence primary key. */
    public readonly int|string $sequenceId;

    /** Sequence definition version that produced this occurrence. */
    public readonly int $definitionVersion;

    /** Monotonic occurrence number within the definition version. */
    public readonly int $number;

    /** Stable idempotency key. */
    public readonly string $key;

    /** Normalized configured offset or recurrence marker. */
    public readonly string $offset;

    /** Intended execution time. */
    public readonly CarbonImmutable $scheduledAt;

    /** Persisted occurrence record. */
    public readonly ScheduledSequenceOccurrence $record;

    /** Persisted owning sequence record. */
    public readonly ScheduledSequenceModel $sequence;

    /**
     * Build an immutable occurrence view from persisted records.
     */
    public function __construct(ScheduledSequenceOccurrence $record, ScheduledSequenceModel $sequence)
    {
        $this->id = $record->getKey();
        $this->sequenceId = $sequence->getKey();
        $this->definitionVersion = $record->definition_version;
        $this->number = $record->occurrence_number;
        $this->key = $record->occurrence_key;
        $this->offset = $record->offset;
        $this->scheduledAt = CarbonImmutable::instance($record->scheduled_at);
        $this->record = $record;
        $this->sequence = $sequence;
    }
}
