<?php

namespace AiSoft\ScheduledSequence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable execution record for one logical sequence occurrence.
 *
 * @property int|string $id
 * @property int|string $scheduled_sequence_id
 * @property int $definition_version
 * @property int $occurrence_number
 * @property string $occurrence_key
 * @property string $offset
 * @property \Carbon\CarbonImmutable $scheduled_at
 * @property string $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable $available_at
 * @property \Carbon\CarbonImmutable|null $claimed_at
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property string|null $last_error
 * @property-read ScheduledSequence|null $sequence
 */
class ScheduledSequenceOccurrence extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STALE = 'stale';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'definition_version' => 'integer',
        'occurrence_number' => 'integer',
        'attempts' => 'integer',
        'scheduled_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    /**
     * Resolve the configured occurrence table.
     */
    public function getTable(): string
    {
        return config('scheduled-sequence.occurrences_table', 'scheduled_sequence_occurrences');
    }

    /**
     * Get the sequence that created this occurrence.
     *
     * @return BelongsTo<ScheduledSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(
            config('scheduled-sequence.model', ScheduledSequence::class),
            'scheduled_sequence_id',
        );
    }

    /**
     * Limit a query to pending occurrences that are ready for publication.
     */
    public function scopePublishable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where('available_at', '<=', now());
    }

    /**
     * Determine whether this occurrence has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_STALE,
            self::STATUS_CANCELLED,
        ], true);
    }
}
