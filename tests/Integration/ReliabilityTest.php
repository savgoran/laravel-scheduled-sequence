<?php

namespace AiSoft\ScheduledSequence\Tests\Integration;

use AiSoft\ScheduledSequence\Jobs\ExecuteScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Services\OccurrencePublisher;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use AiSoft\ScheduledSequence\Tests\Fixtures\TestOrigin;
use AiSoft\ScheduledSequence\Tests\Integration\Fixtures\ReliabilitySequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReliabilityTest extends ReliabilityTestCase
{
    public function test_two_processes_race_on_the_same_due_sequence_deterministically(): void
    {
        $origin = TestOrigin::query()->create();
        $sequence = ReliabilitySequence::start($origin);
        $sequenceId = (string) $sequence->getSequenceRecord()->getKey();
        $barrier = $this->newBarrier();
        $directory = dirname($barrier->path('placeholder'));

        $first = $this->startWorker('materialize-race', [$sequenceId, $directory, 'first']);
        $second = $this->startWorker('materialize-race', [$sequenceId, $directory, 'second']);

        $firstReady = $this->waitForBarrier($barrier, 'ready-first', $first, $second);
        $secondReady = $this->waitForBarrier($barrier, 'ready-second', $first, $second);
        $this->assertSame($sequenceId, (string) $firstReady['sequence_id']);
        $this->assertSame($sequenceId, (string) $secondReady['sequence_id']);

        $barrier->release('race');
        $this->waitForWorker($first);
        $this->waitForWorker($second);

        $firstResult = $barrier->waitFor('result-first');
        $secondResult = $barrier->waitFor('result-second');
        $this->assertSame(
            1,
            count($firstResult['occurrence_ids']) + count($secondResult['occurrence_ids']),
        );
        $this->assertDatabaseCount('scheduled_sequence_occurrences', 1);

        $this->publishAndWorkToCompletion();

        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $this->occurrence()->status);
        $this->assertDatabaseCount('reliability_deliveries', 1);
        $this->assertDatabaseCount('reliability_effects', 1);
    }

    public function test_a_committed_occurrence_survives_a_crash_before_publication(): void
    {
        $origin = TestOrigin::query()->create();
        $sequence = ReliabilitySequence::start($origin);
        $barrier = $this->newBarrier();
        $directory = dirname($barrier->path('placeholder'));
        $publisherProcess = $this->startWorker('materialize-and-pause', [
            (string) $sequence->getSequenceRecord()->getKey(),
            $directory,
        ]);

        $committed = $this->waitForBarrier($barrier, 'occurrence-committed', $publisherProcess);
        $occurrence = ScheduledSequenceOccurrence::query()->findOrFail($committed['occurrence_id']);
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($committed['occurrence_key'], $occurrence->occurrence_key);
        $this->assertDatabaseCount('jobs', 0);

        $this->stopWorker($publisherProcess);
        $this->publishAndWorkToCompletion();

        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $occurrence->fresh()->status);
        $this->assertDatabaseHas('reliability_effects', [
            'occurrence_key' => $committed['occurrence_key'],
        ]);
    }

    public function test_recovery_republishes_after_queue_acceptance_without_duplicate_effects(): void
    {
        $origin = TestOrigin::query()->create();
        $sequence = ReliabilitySequence::start($origin);
        app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );
        $occurrence = $this->occurrence();
        $barrier = $this->newBarrier();
        $directory = dirname($barrier->path('placeholder'));
        $publisherProcess = $this->startWorker('publish-and-pause', [$directory]);

        $this->waitForBarrier($barrier, 'queue-accepted', $publisherProcess);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_PENDING, $occurrence->fresh()->status);

        $this->stopWorker($publisherProcess);

        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(
            [$occurrence->getKey(), $occurrence->getKey()],
            $this->queuedOccurrenceIds(),
        );

        $this->workToCompletion();

        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $occurrence->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('reliability_deliveries', 1);
        $this->assertDatabaseCount('reliability_effects', 1);
    }

    public function test_a_worker_crash_reuses_the_key_and_an_idempotent_consumer_applies_one_effect(): void
    {
        $origin = TestOrigin::query()->create();
        $sequence = ReliabilitySequence::start($origin);
        app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );
        $occurrence = $this->occurrence();
        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());

        $barrier = $this->newBarrier();
        $directory = dirname($barrier->path('placeholder'));
        $worker = $this->startWorker('work', [$directory]);
        $effect = $this->waitForBarrier($barrier, 'effect-applied', $worker);

        $this->assertSame($occurrence->occurrence_key, $effect['occurrence_key']);
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_RUNNING, $occurrence->fresh()->status);
        $this->assertDatabaseCount('reliability_deliveries', 1);
        $this->assertDatabaseCount('reliability_effects', 1);

        $this->stopWorker($worker);
        DB::table('jobs')->update(['reserved_at' => time() - 10]);
        $recoveryTime = Carbon::now('UTC');
        ScheduledSequenceOccurrence::query()->whereKey($occurrence->getKey())->update([
            'claimed_at' => $recoveryTime->clone()->subSeconds(2),
        ]);
        $this->assertSame(
            1,
            app(ScheduledSequenceRunner::class)->recoverAbandonedOccurrences($recoveryTime),
        );
        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());

        $this->workToCompletion();

        $deliveryKeys = DB::table('reliability_deliveries')
            ->orderBy('id')
            ->pluck('occurrence_key')
            ->all();
        $this->assertGreaterThanOrEqual(2, count($deliveryKeys));
        $this->assertSame([$occurrence->occurrence_key], array_values(array_unique($deliveryKeys)));
        $this->assertDatabaseCount('reliability_effects', 1);
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $occurrence->fresh()->status);
    }

    private function publishAndWorkToCompletion(): void
    {
        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());
        $this->workToCompletion();
    }

    private function workToCompletion(): void
    {
        $worker = $this->startWorker('work');
        $this->waitForWorker($worker);
    }

    private function occurrence(): ScheduledSequenceOccurrence
    {
        return ScheduledSequenceOccurrence::query()->sole();
    }

    /**
     * @return list<int|string>
     */
    private function queuedOccurrenceIds(): array
    {
        return DB::table('jobs')
            ->orderBy('id')
            ->pluck('payload')
            ->map(function (string $payload): int|string {
                $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                $job = unserialize($decoded['data']['command'], [
                    'allowed_classes' => [ExecuteScheduledSequenceOccurrence::class],
                ]);

                $this->assertInstanceOf(ExecuteScheduledSequenceOccurrence::class, $job);

                return $job->occurrenceId;
            })
            ->all();
    }
}
