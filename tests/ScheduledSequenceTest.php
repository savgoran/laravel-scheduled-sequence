<?php

namespace AiSoft\ScheduledSequence\Tests;

use AiSoft\ScheduledSequence\Jobs\ExecuteScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\Models\ScheduledSequence as ScheduledSequenceModel;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use AiSoft\ScheduledSequence\Services\OccurrenceExecutor;
use AiSoft\ScheduledSequence\Services\OccurrenceGuard;
use AiSoft\ScheduledSequence\Services\OccurrencePublisher;
use AiSoft\ScheduledSequence\Services\ScheduledSequenceRunner;
use AiSoft\ScheduledSequence\Tests\Fixtures\FailingSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\GuardedSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\OneShotSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\RecordingOneShotSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\RecordingSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\RecurringSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\RememberedSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\RepeatingSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\ReplaySequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\SkipSequence;
use AiSoft\ScheduledSequence\Tests\Fixtures\TestOrigin;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

class ScheduledSequenceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RecordingSequence::$handled = [];
        FailingSequence::$attemptedKeys = [];
        parent::tearDown();
    }

    public function test_it_creates_executes_and_advances_a_model_sequence(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RecordingSequence::start($origin, 42);

        $this->assertSame('2026-01-02 10:00:00', $sequence->getSequenceRecord()->next_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-01-02 10:00:00');
        $this->assertTrue($sequence->runDue());

        $record = $sequence->getSequenceRecord()->fresh();
        $occurrence = ScheduledSequenceOccurrence::query()->sole();

        $this->assertSame('2026-01-04 10:00:00', $record->next_at->format('Y-m-d H:i:s'));
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $occurrence->status);
        $this->assertSame([$occurrence->occurrence_key], RecordingSequence::$handled);
    }

    public function test_it_rejects_a_handler_that_is_not_a_scheduled_sequence(): void
    {
        $sequenceRecord = new ScheduledSequenceModel(['handler' => self::class, 'start_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        ScheduledSequence::createFromSequence($sequenceRecord);
    }

    public function test_it_increments_the_definition_version_when_restarted(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();

        $first = OneShotSequence::start($origin)->getSequenceRecord();
        $second = OneShotSequence::start($origin)->getSequenceRecord();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(2, $second->definition_version);
        $this->assertSame(1, $second->next_occurrence_number);
    }

    public function test_a_second_runner_does_not_materialize_the_same_due_occurrence(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $runner = app(ScheduledSequenceRunner::class);

        $first = $runner->materializeSequence($sequence->getSequenceRecord()->getKey());
        $second = $runner->materializeSequence($sequence->getSequenceRecord()->getKey());

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, ScheduledSequenceOccurrence::query()->count());
    }

    public function test_duplicate_jobs_only_execute_an_occurrence_once(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RecordingOneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];
        $executor = app(OccurrenceExecutor::class);

        $this->assertTrue($executor->execute($id));
        $this->assertFalse($executor->execute($id));
        $this->assertCount(1, RecordingSequence::$handled);
    }

    public function test_a_final_occurrence_executes_after_the_sequence_is_marked_completed(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RecordingOneShotSequence::start($origin);
        $runner = app(ScheduledSequenceRunner::class);

        $occurrenceId = $runner->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        $this->assertSame(
            ScheduledSequenceModel::STATUS_COMPLETED,
            $sequence->getSequenceRecord()->fresh()->status,
        );
        $this->assertTrue(app(OccurrenceExecutor::class)->execute($occurrenceId));
        $this->assertCount(1, RecordingSequence::$handled);
    }

    public function test_a_non_permanent_sequence_is_pruned_after_its_retention_period(): void
    {
        config()->set('scheduled-sequence.terminal_retention_seconds', 0);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $recordId = $sequence->getSequenceRecord()->getKey();

        $sequence->runDue();
        $this->assertDatabaseHas('scheduled_sequences', ['id' => $recordId]);

        $this->assertSame(1, app(ScheduledSequenceRunner::class)->pruneTerminalSequences());
        $this->assertDatabaseMissing('scheduled_sequences', ['id' => $recordId]);
        $this->assertDatabaseCount('scheduled_sequence_occurrences', 0);
    }

    public function test_it_can_remember_a_completed_sequence_permanently(): void
    {
        config()->set('scheduled-sequence.terminal_retention_seconds', 0);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RememberedSequence::start($origin);

        $sequence->getSequenceRecord()->remember('actions.notice_sent', true);
        $sequence->runDue();

        $record = $sequence->getSequenceRecord()->fresh();

        $this->assertSame(ScheduledSequenceModel::STATUS_COMPLETED, $record->status);
        $this->assertTrue($record->recall('actions.notice_sent'));
        $this->assertSame(0, app(ScheduledSequenceRunner::class)->pruneTerminalSequences());
        $this->assertDatabaseHas('scheduled_sequences', ['id' => $record->getKey()]);
    }

    public function test_it_repeats_after_the_last_offset_and_coalesces_missed_periods(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RepeatingSequence::start($origin);

        Carbon::setTestNow('2026-01-10 10:00:00');
        $ids = app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );

        $occurrence = ScheduledSequenceOccurrence::query()->findOrFail($ids[0]);
        $this->assertCount(1, $ids);
        $this->assertSame('repeat', $occurrence->offset);
        $this->assertSame('2026-01-10 10:00:00', $occurrence->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-01-13 10:00:00',
            $sequence->getSequenceRecord()->fresh()->next_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_replay_all_materializes_every_missed_offset_up_to_the_limit(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = ReplaySequence::start($origin);

        Carbon::setTestNow('2026-01-08 10:00:00');
        $ids = app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );

        $this->assertCount(3, $ids);
        $this->assertSame(
            ['1day', '3days', '7days'],
            ScheduledSequenceOccurrence::query()->orderBy('occurrence_number')->pluck('offset')->all(),
        );
    }

    public function test_replay_all_leaves_additional_overdue_work_for_the_next_run(): void
    {
        config()->set('scheduled-sequence.replay_limit', 2);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = ReplaySequence::start($origin);

        Carbon::setTestNow('2026-01-08 10:00:00');
        $ids = app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );
        $record = $sequence->getSequenceRecord()->fresh();

        $this->assertCount(2, $ids);
        $this->assertSame(ScheduledSequenceModel::STATUS_ACTIVE, $record->status);
        $this->assertSame('2026-01-08 10:00:00', $record->next_at->format('Y-m-d H:i:s'));
    }

    public function test_skip_policy_advances_without_materializing_missed_work(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = SkipSequence::start($origin);

        Carbon::setTestNow('2026-01-08 10:00:00');
        $ids = app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );

        $this->assertSame([], $ids);
        $this->assertSame(
            ScheduledSequenceModel::STATUS_COMPLETED,
            $sequence->getSequenceRecord()->fresh()->status,
        );
    }

    public function test_it_restarts_a_recurring_offset_sequence_from_scheduled_time(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RecurringSequence::start($origin);

        Carbon::setTestNow('2026-01-04 12:00:00');
        app(ScheduledSequenceRunner::class)->materializeSequence(
            $sequence->getSequenceRecord()->getKey(),
        );
        $record = $sequence->getSequenceRecord()->fresh();

        $this->assertSame('2026-01-04 10:00:00', $record->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-05 10:00:00', $record->next_at->format('Y-m-d H:i:s'));
    }

    public function test_should_continue_cancels_without_calling_handle(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = GuardedSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        $this->assertFalse(app(OccurrenceExecutor::class)->execute($id));
        $this->assertSame(
            ScheduledSequenceModel::STATUS_CANCELLED,
            $sequence->getSequenceRecord()->fresh()->status,
        );
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_CANCELLED,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_restarting_a_sequence_makes_a_published_occurrence_stale(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        OneShotSequence::start($origin);

        $this->assertFalse(app(OccurrenceExecutor::class)->execute($id));
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_STALE,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_cancelling_a_sequence_makes_a_published_occurrence_stale(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        $sequence->getSequenceRecord()->fresh()->markCancelled();

        $this->assertFalse(app(OccurrenceExecutor::class)->execute($id));
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_STALE,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_retries_keep_the_same_occurrence_identity(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = FailingSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];
        $executor = app(OccurrenceExecutor::class);

        foreach ([1, 2] as $attempt) {
            try {
                $executor->execute($id);
            } catch (RuntimeException) {
                // The queue owns retry timing; the occurrence identity remains stable.
            }

            $this->assertSame($attempt, ScheduledSequenceOccurrence::query()->findOrFail($id)->attempts);
        }

        $this->assertCount(2, FailingSequence::$attemptedKeys);
        $this->assertSame(FailingSequence::$attemptedKeys[0], FailingSequence::$attemptedKeys[1]);
        $this->assertSame(
            RuntimeException::class,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->last_error,
        );
    }

    public function test_a_pending_occurrence_is_recoverably_published(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());

        Queue::assertPushed(
            ExecuteScheduledSequenceOccurrence::class,
            fn (ExecuteScheduledSequenceOccurrence $job): bool => $job->occurrenceId === $id,
        );
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_PUBLISHED,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_a_fast_sync_worker_does_not_regress_to_published_status(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        $this->assertSame(1, app(OccurrencePublisher::class)->publishPending());
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_SUCCEEDED,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_an_abandoned_running_occurrence_is_recovered(): void
    {
        config()->set('scheduled-sequence.abandoned_after_seconds', 60);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];

        ScheduledSequenceOccurrence::query()->whereKey($id)->update([
            'status' => ScheduledSequenceOccurrence::STATUS_RUNNING,
            'claimed_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(1, app(ScheduledSequenceRunner::class)->recoverAbandonedOccurrences());
        $this->assertSame(
            ScheduledSequenceOccurrence::STATUS_PENDING,
            ScheduledSequenceOccurrence::query()->findOrFail($id)->status,
        );
    }

    public function test_the_runner_is_registered_with_the_laravel_scheduler(): void
    {
        $registered = collect(app(Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'scheduled-sequence:run'));

        $this->assertTrue($registered);
    }

    public function test_the_runner_command_materializes_publishes_and_executes_due_work(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = RecordingOneShotSequence::start($origin);

        $this->artisan('scheduled-sequence:run')->assertSuccessful();

        $occurrence = ScheduledSequenceOccurrence::query()->sole();
        $this->assertSame($sequence->getSequenceRecord()->getKey(), $occurrence->scheduled_sequence_id);
        $this->assertSame(ScheduledSequenceOccurrence::STATUS_SUCCEEDED, $occurrence->status);
        $this->assertSame([$occurrence->occurrence_key], RecordingSequence::$handled);
    }

    public function test_occurrence_guard_rejects_cancelled_work(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $origin = TestOrigin::query()->create();
        $sequence = OneShotSequence::start($origin);
        $id = app(ScheduledSequenceRunner::class)
            ->materializeSequence($sequence->getSequenceRecord()->getKey())[0];
        $occurrence = ScheduledSequenceOccurrence::query()->findOrFail($id);

        $sequence->getSequenceRecord()->fresh()->markCancelled();

        $this->assertFalse(app(OccurrenceGuard::class)->allows($occurrence->occurrence_key));
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->app['db']->connection()->getSchemaBuilder()->create('test_origins', function ($table): void {
            $table->id();
            $table->timestamps();
        });
    }
}
