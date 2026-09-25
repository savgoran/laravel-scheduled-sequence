# [Proposal] Scheduled Sequences — persistent irregular application schedules

## Summary

This proposal explores a first-class Laravel abstraction for persistent sequences of actions that occur at irregular times relative to an entity-specific start time.

A Scheduled Sequence stores queryable scheduling state outside the queue, evaluates current application state before each occurrence, and may complete, cancel, restart, or recur.

| Mechanism | Responsibility |
| --- | --- |
| Laravel Scheduler | Determines when the application checks for due work |
| Queue | Executes and retries a unit of work |
| Scheduled Sequence | Owns persistent entity-specific temporal state and occurrence identity |

## Motivation

Consider an account that becomes unpaid:

```text
immediately
+1 day at 10:00
+2 days at 10:00
+4 days at 10:00
+7 days at 10:00
+10 days at 10:00
+15 days at 10:00
+20 days at 10:00
then every 5 days
```

Every account starts independently. Before each action, the application must verify that the account is still unpaid. If it has been paid, remaining work should stop.

Laravel can implement this with delayed jobs and application-owned database state. The proposed abstraction makes that state explicit and reusable:

- current definition and position;
- next due time;
- cancellation and restart;
- irregular offsets and indefinite recurrence;
- concurrency and downtime policy;
- stable identity for one logical occurrence.

## Illustrative API

```php
final class AccountLeftUnpaidSequence extends ScheduledSequence
{
    protected array $offsets = [
        'now',
        '1 day 10am',
        '2 days 10am',
        '4 days 10am',
        '7 days 10am',
        '10 days 10am',
        '15 days 10am',
        '20 days 10am',
    ];

    protected ?string $repeatEvery = '5 days';

    protected function shouldContinue(Occurrence $occurrence): bool
    {
        return $this->model->isUnpaid();
    }

    protected function handle(Occurrence $occurrence): void
    {
        SendAccountReminder::dispatch(
            $this->model,
            $occurrence->key,
        );
    }
}

AccountLeftUnpaidSequence::start($account);
```

The syntax and namespace are illustrative. The proposal concerns the abstraction and its execution contract.

## Explicit completion boundary

The reference implementation uses this contract:

> Scheduled Sequence owns dispatch timing and durable handoff. Laravel Queue owns execution attempts and retries after handoff.

The sequence does not wait for arbitrary downstream jobs to report business completion. Doing so would move the feature toward a general workflow/orchestration engine.

An occurrence is durably represented before the sequence advances. Its stable identity is:

```text
(sequence_id, definition_version, occurrence_number)
```

The same identity is reused across retries and can be passed to external systems as an idempotency key.

## Stale queued work

Checking `shouldContinue()` when work is scheduled is insufficient if a queued job runs later:

```text
10:00 occurrence dispatched
10:10 account paid or sequence restarted
10:30 worker begins
```

The worker reloads scheduling state immediately before handling and compares the expected definition version and cancellation status. A cancelled or superseded occurrence becomes stale.

Application jobs dispatched by `handle()` can use the same occurrence identity to repeat that check immediately before their external side effect.

## Durable handoff

`afterCommit()` prevents a worker from observing uncommitted state but does not close the crash gap after commit and before queue publication.

The reference package therefore uses occurrence rows as a small local outbox:

```text
transaction
 ├─ advance sequence
 └─ create pending occurrence
        ↓
publisher
        ↓
Laravel Queue
```

If publication is interrupted, the pending occurrence remains recoverable. Duplicate publication is safe because only one job can atomically claim an occurrence.

The ledger stores due occurrences, not every future occurrence.

## Persistence

The sequence stores the authoritative future state:

```text
handler
entity identity
start_at
next_at
status
definition_version
next_occurrence_number
catch_up_policy
```

Future dates are derived from the PHP definition. The occurrence ledger stores only work that has become due.

This differs from scheduling all future delayed jobs, where queue payloads become the implicit representation of future application state.

## Downtime semantics

Downtime behavior should be an explicit contract:

- `coalesce_latest`: run one occurrence for the latest overdue position;
- `replay_all`: materialize missed positions in order, with a limit;
- `skip`: advance without executing missed work.

Recurrence should be anchored to intended scheduled time rather than actual worker completion time, preventing queue latency from shifting the schedule.

## Concurrency and failures

Due work is claimed with a database row lock and a unique occurrence constraint. A cache lock may reduce contention but is not the correctness boundary.

Laravel Queue retries a failed occurrence using the same identity. Abandoned running claims return to pending after a timeout.

Exactly-once external side effects remain a non-goal. Destinations should use the occurrence key for idempotency where supported.

## Relationship to existing Laravel features

Scheduled Sequences do not replace Scheduler or Queue:

```php
Schedule::command('scheduled-sequence:run')->everyMinute();
```

The Scheduler decides when to inspect due sequences. The sequence decides what is due for one entity. Queue executes the resulting occurrence.

Delayed jobs remain suitable for many finite cases. Scheduled Sequences are useful when the application needs queryable, cancellable, restartable, long-lived scheduling state independent of the queue backend.

## Reference implementation

The `aisoft/laravel-scheduled-sequence` package implements:

- class-based definitions and relative offsets;
- model ownership through an Eloquent morph;
- durable occurrence identity and status;
- atomic materialization;
- recoverable queue publication;
- stale-job protection through definition versions;
- catch-up policies and scheduled-time recurrence;
- retained JSON memory and terminal cleanup;
- automatic minute-runner registration;
- fault-oriented tests for duplicate runners, publication gaps, stale jobs, and retries.

The package remains useful even if the abstraction stays outside Laravel core. Its purpose as a reference implementation is to validate the contract through real application use.

## Discussion questions

1. Would Laravel benefit from a first-class representation of persistent schedules whose timing belongs to individual application entities?
2. Is dispatch timing the correct default completion boundary?
3. Is an occurrence ledger an appropriate reliability boundary, or should durable publication remain entirely application-owned?
4. Should definitions remain class-based, or should a fluent API complement them?
5. Which downtime policy should be the default?
6. Does model ownership belong in the core abstraction, or should stable string keys also be first-class?
