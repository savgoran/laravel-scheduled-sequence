<?php

namespace AiSoft\ScheduledSequence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;

/**
 * Persisted state for one handler and sequenceable-model pair.
 *
 * @property int|string $id
 * @property class-string<\AiSoft\ScheduledSequence\ScheduledSequence> $handler
 * @property int|string $sequenceable_id
 * @property string $sequenceable_type
 * @property int|string|null $owner_id
 * @property \Illuminate\Support\Carbon $start_at
 * @property \Illuminate\Support\Carbon|null $end_at
 * @property \Illuminate\Support\Carbon|null $next_at
 * @property string $status
 * @property int $definition_version
 * @property int $next_occurrence_number
 * @property string $catch_up_policy
 * @property array<string, mixed>|null $memory
 * @property-read Model|null $sequenceable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ScheduledSequenceOccurrence> $occurrences
 */
class ScheduledSequence extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'next_at' => 'datetime',
        'definition_version' => 'integer',
        'next_occurrence_number' => 'integer',
        'memory' => 'array',
    ];

    /**
     * Register model lifecycle hooks.
     */
    protected static function booted(): void
    {
        static::deleting(function (ScheduledSequence $sequence): void {
            $sequence->occurrences()->delete();
        });
    }

    /**
     * Resolve the configured sequence table.
     */
    public function getTable(): string
    {
        return config('scheduled-sequence.table', 'scheduled_sequences');
    }

    /**
     * Get the model that owns this sequence.
     *
     * @return MorphTo<Model, $this>
     */
    public function sequenceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get occurrences created by this sequence.
     *
     * @return HasMany<ScheduledSequenceOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(
            config('scheduled-sequence.occurrence_model', ScheduledSequenceOccurrence::class),
            'scheduled_sequence_id',
        );
    }

    /**
     * Store a value in the sequence memory document.
     */
    public function remember(string $key, mixed $value = true): bool
    {
        $memory = $this->memory ?? [];
        Arr::set($memory, $key, $value);

        return $this->updateQuietly(['memory' => $memory]);
    }

    /**
     * Read a value from the sequence memory document.
     */
    public function recall(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->memory ?? [], $key, $default);
    }

    /**
     * Determine whether a memory key exists.
     */
    public function hasMemory(string $key): bool
    {
        return Arr::has($this->memory ?? [], $key);
    }

    /**
     * Remove a value from the sequence memory document.
     */
    public function forgetMemory(string $key): bool
    {
        $memory = $this->memory ?? [];
        Arr::forget($memory, $key);

        return $this->updateQuietly(['memory' => $memory]);
    }

    /**
     * Mark the sequence as completed.
     */
    public function markCompleted(): bool
    {
        return $this->updateQuietly([
            'status' => self::STATUS_COMPLETED,
            'end_at' => now(),
            'next_at' => null,
        ]);
    }

    /**
     * Mark the sequence as explicitly cancelled.
     */
    public function markCancelled(): bool
    {
        return $this->updateQuietly([
            'status' => self::STATUS_CANCELLED,
            'end_at' => now(),
            'next_at' => null,
        ]);
    }
}
