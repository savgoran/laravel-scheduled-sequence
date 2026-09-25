<?php

namespace AiSoft\ScheduledSequence;

use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;
use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Services\OccurrenceExecutor;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Base class for durable, model-specific scheduled sequences.
 */
abstract class ScheduledSequence
{
    /** @var list<string> Relative offsets accepted by DateTime::modify(). */
    protected array $offsets = [];

    /** Repeat the complete offset sequence after its final occurrence. */
    protected bool $repeatSequence = false;

    /** Recurring interval applied after the finite offset sequence. */
    protected ?string $repeatEvery = null;

    /** @deprecated Use $repeatSequence. */
    protected bool $recurrence = false;

    /** @deprecated Use $repeatEvery. */
    protected ?string $repeatEveryAfterLastOffset = null;

    /** Default business-state switch used by shouldContinue(). */
    protected bool $enabled = true;

    /** Prevent automatic pruning of terminal sequence state. */
    protected bool $rememberPermanently = false;

    /** Optional per-handler catch-up policy override. */
    protected ?string $catchUpPolicy = null;

    /** Persisted sequence state. */
    protected ScheduledSequenceModel $sequenceRecord;

    /** Sequence anchor represented in the configured timezone. */
    protected Carbon $startDate;

    /** Timezone used to calculate schedule positions. */
    private string $timeZone;

    /** @var array<string, Carbon> Normalized offsets and their scheduled times. */
    private array $periods = [];

    /**
     * Create a handler around its persisted sequence state.
     */
    public function __construct(ScheduledSequenceModel $sequenceRecord, ?string $timeZone = null)
    {
        $this->timeZone = $timeZone ?? config('scheduled-sequence.timezone', 'UTC');
        $this->startDate = Carbon::instance($sequenceRecord->start_at)->setTimezone($this->timeZone);
        $this->sequenceRecord = $sequenceRecord;
    }

    /**
     * Instantiate the handler stored on a sequence record.
     *
     * @throws InvalidArgumentException
     */
    public static function createFromSequence(ScheduledSequenceModel $sequenceRecord): static
    {
        $class = $sequenceRecord->handler;
        if (! is_string($class) || ! is_subclass_of($class, self::class)) {
            throw new InvalidArgumentException("Invalid scheduled sequence handler [{$class}].");
        }

        return new $class($sequenceRecord);
    }

    /**
     * Start or restart this handler for an origin model.
     *
     * Restarting increments the definition version and invalidates older work.
     */
    public static function start(Model $originModel, int|string|null $ownerId = null, ?Carbon $startAt = null): static
    {
        $model = config('scheduled-sequence.model', ScheduledSequenceModel::class);
        $prototype = new $model();
        $connection = DB::connection($prototype->getConnectionName());

        return $connection->transaction(function () use ($model, $originModel, $ownerId, $startAt): static {
            $identity = [
                'handler' => static::class,
                'sequenceable_id' => $originModel->getKey(),
                'sequenceable_type' => $originModel->getMorphClass(),
            ];

            /** @var ScheduledSequenceModel|null $sequenceRecord */
            $sequenceRecord = $model::query()
                ->where($identity)
                ->lockForUpdate()
                ->first();

            $definitionVersion = $sequenceRecord
                ? max(1, (int) $sequenceRecord->definition_version + 1)
                : 1;

            if (! $sequenceRecord) {
                $sequenceRecord = new $model($identity);
            }

            $sequenceRecord->forceFill([
                'owner_id' => $ownerId,
                'start_at' => $startAt ?? Carbon::now(config('scheduled-sequence.timezone', 'UTC')),
                'end_at' => null,
                'next_at' => null,
                'status' => ScheduledSequenceModel::STATUS_ACTIVE,
                'definition_version' => $definitionVersion,
                'next_occurrence_number' => 1,
            ]);
            $sequenceRecord->saveQuietly();

            $instance = new static($sequenceRecord);
            $instance->createSequence();
            $nextAt = $instance->getNextPeriod();
            if (! $nextAt instanceof Carbon) {
                throw new LogicException('A scheduled sequence must define at least one offset.');
            }

            $sequenceRecord->forceFill([
                'catch_up_policy' => $instance->configuredCatchUpPolicy(),
                'next_at' => $nextAt,
            ])->saveQuietly();

            return $instance;
        });
    }

    /**
     * Start or restart this handler for an origin model.
     *
     * @deprecated Use start() for new integrations.
     */
    public static function init(Model $originModel, int|string|null $ownerId = null, ?Carbon $startAt = null): static
    {
        return static::start($originModel, $ownerId, $startAt);
    }

    /**
     * Build normalized schedule positions from the configured offsets.
     */
    public function createSequence(): static
    {
        $this->periods = [];
        $previous = null;

        foreach ($this->offsets as $offset) {
            $key = Str::slug($offset, '');
            if ($key === '' || array_key_exists($key, $this->periods)) {
                throw new InvalidArgumentException("Scheduled sequence offset [{$offset}] does not have a unique key.");
            }

            $period = $this->startDate->clone()->modify($offset);
            if ($previous instanceof Carbon && $period->lessThanOrEqualTo($previous)) {
                throw new InvalidArgumentException(
                    'Scheduled sequence offsets must move forward in their declared order.',
                );
            }

            $this->periods[$key] = $period;
            $previous = $period;
        }

        return $this;
    }

    /**
     * Materialize and synchronously execute due occurrences for this sequence.
     *
     * This method is retained for direct-execution compatibility.
     */
    public function runDue(?Carbon $currentTime = null): bool
    {
        $occurrenceIds = app(ScheduledSequenceRunner::class)->materializeSequence(
            $this->sequenceRecord->getKey(),
            $currentTime,
        );

        foreach ($occurrenceIds as $occurrenceId) {
            app(OccurrenceExecutor::class)->execute($occurrenceId);
        }

        $fresh = $this->sequenceRecord->newQuery()->find($this->sequenceRecord->getKey());
        if ($fresh) {
            $this->sequenceRecord = $fresh;
            $this->startDate = Carbon::instance($fresh->start_at)->setTimezone($this->timeZone);
        } else {
            $this->sequenceRecord->exists = false;
        }

        return $occurrenceIds !== [];
    }

    /**
     * Materialize due occurrences while the sequence row is locked by the caller.
     *
     * @return array<int, int|string>
     */
    public function materializeDueOccurrences(Carbon $currentTime): array
    {
        $this->createSequence();
        $policy = CatchUpPolicy::validate(
            $this->sequenceRecord->catch_up_policy ?: $this->configuredCatchUpPolicy(),
        );
        $replayLimit = max(1, (int) config('scheduled-sequence.replay_limit', 100));
        $scanLimit = max($replayLimit, (int) config('scheduled-sequence.catch_up_scan_limit', 10000));
        $candidates = [];
        $scanned = 0;

        while (
            $this->sequenceRecord->status === ScheduledSequenceModel::STATUS_ACTIVE
            && $this->sequenceRecord->next_at
            && Carbon::instance($this->sequenceRecord->next_at)->lessThanOrEqualTo($currentTime)
        ) {
            if (++$scanned > $scanLimit) {
                throw new LogicException(
                    "Scheduled sequence catch-up exceeded the configured scan limit [{$scanLimit}].",
                );
            }

            $scheduledAt = Carbon::instance($this->sequenceRecord->next_at)->setTimezone($this->timeZone);
            $offset = $this->offsetForScheduledAt($scheduledAt);
            $number = max(1, (int) $this->sequenceRecord->next_occurrence_number);
            $candidate = [
                'definition_version' => (int) $this->sequenceRecord->definition_version,
                'occurrence_number' => $number,
                'occurrence_key' => $this->occurrenceKey($number),
                'offset' => $offset,
                'scheduled_at' => $scheduledAt,
                'status' => ScheduledSequenceOccurrence::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $currentTime,
            ];

            $this->advanceAfter($offset, $scheduledAt, $currentTime);
            $this->sequenceRecord->next_occurrence_number = $number + 1;

            if ($policy === CatchUpPolicy::REPLAY_ALL) {
                $candidates[] = $candidate;
                if (count($candidates) >= $replayLimit) {
                    break;
                }
            } elseif ($policy === CatchUpPolicy::COALESCE_LATEST) {
                $candidates = [$candidate];
            }
        }

        $this->sequenceRecord->saveQuietly();

        $ids = [];

        foreach ($candidates as $candidate) {
            /** @var ScheduledSequenceOccurrence $occurrence */
            $occurrence = $this->sequenceRecord->occurrences()->firstOrCreate([
                'definition_version' => $candidate['definition_version'],
                'occurrence_number' => $candidate['occurrence_number'],
            ], $candidate);
            $ids[] = $occurrence->getKey();
        }

        return $ids;
    }

    /**
     * Return the first period or the period after a normalized offset.
     */
    public function getNextPeriod(?string $currentPeriod = null): Carbon|false
    {
        if ($currentPeriod === null) {
            return reset($this->periods);
        }
        $keys = array_keys($this->periods);
        $index = array_search($currentPeriod, $keys, true);

        return $index === false ? false : ($this->periods[$keys[$index + 1] ?? ''] ?? false);
    }

    /**
     * Return calculated schedule positions as ISO-8601 timestamps.
     *
     * @return array<string, string>
     */
    public function getSequenceForHumans(): array
    {
        return array_map(fn (Carbon $period) => $period->toIso8601String(), $this->periods);
    }

    /**
     * Get the persisted sequence state.
     */
    public function getSequenceRecord(): ScheduledSequenceModel
    {
        return $this->sequenceRecord;
    }

    /**
     * Evaluate the handler's current business continuation rule.
     */
    public function canContinue(Occurrence $occurrence): bool
    {
        return $this->shouldContinue($occurrence);
    }

    /**
     * Validate and handle an occurrence.
     *
     * @return bool False when processing cancelled the sequence.
     */
    public function processOccurrence(Occurrence $occurrence): bool
    {
        if (! $this->canContinue($occurrence)) {
            return false;
        }

        $this->handle($occurrence);

        $fresh = $this->sequenceRecord->newQuery()->find($this->sequenceRecord->getKey());
        if ($fresh && $fresh->status === ScheduledSequenceModel::STATUS_CANCELLED) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether terminal state must be retained permanently.
     */
    public function remembersPermanently(): bool
    {
        return $this->rememberPermanently;
    }

    /**
     * Determine whether current business state still permits this occurrence.
     */
    protected function shouldContinue(Occurrence $occurrence): bool
    {
        return $this->enabled;
    }

    /**
     * Handle one occurrence.
     *
     * Override this method in occurrence-aware handlers.
     */
    protected function handle(Occurrence $occurrence): void
    {
        $result = $this->onExpiredOffset($occurrence->offset, $this);
        $method = 'on'.$occurrence->offset;

        if ($result && method_exists($this, $method)) {
            $this->{$method}($occurrence->offset, $this);
        }
    }

    /**
     * Handle a normalized offset through the legacy callback API.
     *
     * @deprecated Override handle(Occurrence $occurrence) instead.
     */
    protected function onExpiredOffset(string $expiredPeriod, ScheduledSequence $sequence): bool
    {
        return true;
    }

    /**
     * Resolve and validate this handler's catch-up policy.
     */
    private function configuredCatchUpPolicy(): string
    {
        return CatchUpPolicy::validate(
            $this->catchUpPolicy ?? config(
                'scheduled-sequence.catch_up_policy',
                CatchUpPolicy::COALESCE_LATEST,
            ),
        );
    }

    /**
     * Resolve the normalized offset represented by a scheduled timestamp.
     */
    private function offsetForScheduledAt(Carbon $scheduledAt): string
    {
        foreach ($this->periods as $offset => $period) {
            if ($period->equalTo($scheduledAt)) {
                return $offset;
            }
        }

        $last = end($this->periods);
        if ($this->repeatInterval() !== null && $last instanceof Carbon && $scheduledAt->greaterThan($last)) {
            return 'repeat';
        }

        throw new LogicException(
            "Unable to identify scheduled sequence occurrence at [{$scheduledAt->toIso8601String()}].",
        );
    }

    /**
     * Advance persisted sequence state after a materialized occurrence.
     */
    private function advanceAfter(string $offset, Carbon $scheduledAt, Carbon $now): void
    {
        $next = $offset === 'repeat' ? false : $this->getNextPeriod($offset);

        if ($next instanceof Carbon) {
            $this->sequenceRecord->next_at = $next;

            return;
        }

        if ($this->repeatInterval() !== null) {
            $this->sequenceRecord->next_at = $this->nextRecurringPeriod($scheduledAt);

            return;
        }

        if ($this->repeatSequence || $this->recurrence) {
            $this->startDate = $scheduledAt->clone();
            $this->createSequence();
            $this->sequenceRecord->start_at = $this->startDate;
            $this->sequenceRecord->next_at = $this->getNextPeriod();

            return;
        }

        $this->sequenceRecord->status = ScheduledSequenceModel::STATUS_COMPLETED;
        $this->sequenceRecord->end_at = $now;
        $this->sequenceRecord->next_at = null;
    }

    /**
     * Calculate the next recurring time from the intended schedule time.
     */
    private function nextRecurringPeriod(Carbon $scheduledAt): Carbon
    {
        $next = $scheduledAt->clone();
        $next->modify($this->repeatInterval());

        if ($next->lessThanOrEqualTo($scheduledAt)) {
            throw new LogicException('The recurring interval must move time forward.');
        }

        return $next;
    }

    /**
     * Resolve the configured recurring interval, including its legacy alias.
     */
    private function repeatInterval(): ?string
    {
        return $this->repeatEvery ?? $this->repeatEveryAfterLastOffset;
    }

    /**
     * Build the stable public idempotency key for an occurrence number.
     */
    private function occurrenceKey(int $number): string
    {
        return implode(':', [
            'scheduled-sequence',
            $this->sequenceRecord->getKey(),
            $this->sequenceRecord->definition_version,
            $number,
        ]);
    }
}
