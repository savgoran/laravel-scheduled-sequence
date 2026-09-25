# RFC: Scheduled Sequences — persistent irregular application schedules

- **Status:** Implemented in the reference package
- **Package:** `aisoft/laravel-scheduled-sequence`
- **Supported Laravel versions:** 10–13

## Summary

A Scheduled Sequence is a persistent, entity-specific temporal schedule. It defines ordered offsets from a start time, stores the next scheduling position, evaluates current application state before execution, and can complete, cancel, repeat, or recur after a finite prefix.

It complements Laravel Scheduler and Queue:

| Mechanism | Responsibility |
| --- | --- |
| Laravel Scheduler | Wakes the package runner |
| Scheduled Sequence | Owns durable scheduling state and occurrence identity |
| Laravel Queue | Executes an occurrence and owns its retry timing |

The reference implementation uses an occurrence ledger as a recoverable local outbox. It does not promise exactly-once external side effects.

## Motivation

Some schedules belong to individual application entities and cannot be expressed as one shared cron expression.

An unpaid account may require actions:

```text
now
+1 day at 10:00
+2 days at 10:00
+4 days at 10:00
+7 days at 10:00
+10 days at 10:00
+15 days at 10:00
+20 days at 10:00
then every 5 days
```

Every account starts independently. Before every occurrence, the application must verify that it is still unpaid. The schedule must remain queryable, cancellable, restartable, and observable without treating delayed queue jobs as the authoritative workflow state.

Laravel already supplies the scheduler and queue primitives. Scheduled Sequence supplies the missing persistent temporal state between them.

## Goals

- Irregular offsets relative to an entity-specific start time.
- One indexed `next_at` value for due-work discovery.
- Stable occurrence identity across retries.
- Atomic sequence advancement and occurrence creation.
- Safe duplicate publication and competing runners.
- Worker-time cancellation and business-condition checks.
- Explicit downtime and recurrence semantics.
- Optional retained business memory.
- Compatibility with normal Laravel queue workers and retries.

## Non-goals

- A general workflow graph or BPM engine.
- Waiting for arbitrary application jobs to report business completion.
- Replacing Laravel Scheduler, Queue, chains, or batches.
- Exactly-once delivery to external systems.
- Storing every future occurrence in advance.

## Public programming model

```php
use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;

final class AccountLeftUnpaidSequence extends ScheduledSequence
{
    protected array $offsets = [
        'now',
        '1 day 10am',
        '3 days 10am',
        '7 days 10am',
    ];

    protected ?string $repeatEvery = '5 days';

    protected function shouldContinue(Occurrence $occurrence): bool
    {
        return $occurrence->sequence->sequenceable?->isUnpaid() === true;
    }

    protected function handle(Occurrence $occurrence): void
    {
        SendAccountReminder::dispatch(
            $occurrence->sequence->sequenceable_id,
            $occurrence->key,
        );
    }
}
```

Start or restart it with:

```php
AccountLeftUnpaidSequence::start($account);
```

The exact API of a potential Laravel framework feature remains open. This document specifies the reference package contract.

## Occurrence identity

An occurrence is one logical position in one sequence definition:

```text
(sequence_id, definition_version, occurrence_number)
```

The package exposes:

```php
$occurrence->id;
$occurrence->sequenceId;
$occurrence->definitionVersion;
$occurrence->number;
$occurrence->key;
$occurrence->offset;
$occurrence->scheduledAt;
$occurrence->sequence;
```

The opaque occurrence key is stable across queue retries and useful for logging, tracing, deduplication, and external idempotency.

Restarting or rescheduling increments `definition_version`. A worker holding an occurrence from an older version treats it as stale.

## Completion boundary

The package uses **dispatch timing semantics**:

> The sequence owns when work becomes due and when it is durably handed off. Laravel Queue owns execution and retries after handoff.

The sequence advances after the occurrence is committed to the local occurrence ledger, not after every possible downstream external action completes.

This boundary prevents Scheduled Sequence from becoming a general orchestration engine. It also requires application jobs dispatched by `handle` to revalidate stale work and use their occurrence key for idempotency.

## Persistence

### Sequence table

| Column | Purpose |
| --- | --- |
| `handler` | Sequence class |
| `sequenceable_type`, `sequenceable_id` | Polymorphic owner |
| `owner_id` | Optional application owner |
| `start_at` | Definition anchor |
| `next_at` | Indexed next due position |
| `status` | `active`, `completed`, or `cancelled` |
| `definition_version` | Invalidates work from older definitions |
| `next_occurrence_number` | Next monotonic logical position |
| `catch_up_policy` | Downtime behavior |
| `end_at` | Terminal timestamp |
| `memory` | Application-owned JSON state |

The handler and polymorphic model tuple is unique.

### Occurrence table

| Column | Purpose |
| --- | --- |
| `scheduled_sequence_id` | Owning sequence |
| `definition_version` | Definition that created it |
| `occurrence_number` | Logical position |
| `occurrence_key` | Stable opaque identity |
| `offset` | Normalized offset or recurrence marker |
| `scheduled_at` | Intended time, independent of worker latency |
| `status` | Execution lifecycle |
| `attempts` | Claim attempts |
| `available_at` | Publication/retry availability |
| claim/publication/start/finish timestamps | Recovery and observation |
| `last_error` | Bounded exception class, with opt-in message storage |

The tuple `(scheduled_sequence_id, definition_version, occurrence_number)` is unique.

## Atomic materialization

The runner processes each due sequence inside a database transaction:

1. select and lock the sequence row;
2. recheck `status` and `next_at` under the lock;
3. derive due logical positions;
4. create uniquely identified occurrence rows;
5. advance or complete the sequence;
6. commit both scheduling state and due work together.

The unique occurrence constraint is the final duplicate-claim guard. Correctness does not depend on a cache lock.

Two runners may discover the same ID before either obtains the row lock. The second runner rechecks the locked row after the first commits and finds that the position has already advanced.

## Recoverable publication

Pending occurrence rows form a local outbox:

```text
transaction commits pending occurrence
        ↓
publisher dispatches occurrence ID
        ↓
worker atomically claims occurrence
        ↓
shouldContinue()
        ↓
handle()
```

If the process stops after the transaction but before queue publication, the next runner republishes the pending occurrence.

If it stops after publication but before marking `published`, the occurrence may be published twice. Both jobs carry the same occurrence ID and only one can atomically transition it to `running`.

Publisher updates use compare-and-set conditions so a fast worker cannot finish and then be regressed to `published`.

## Stale work

Immediately before application handling, the worker reloads the sequence and verifies:

```text
sequence.definition_version == occurrence.definition_version
sequence.status != cancelled
```

`completed` remains valid for the final occurrence of the matching definition. Scheduling responsibility may be complete while final execution is still pending.

After identity validation, `shouldContinue(Occurrence)` evaluates current business state. A false result cancels that sequence definition and marks the occurrence cancelled.

Application jobs dispatched from `handle` may wait in a separate queue. They should use `OccurrenceGuard` immediately before the side effect:

```php
if (! $guard->allows($this->occurrenceKey)) {
    return;
}
```

## Failure semantics

The package execution job uses Laravel Queue attempts and backoff. A thrown exception marks the occurrence failed and is rethrown so the queue can retry it. The exception class is stored by default; bounded message storage is opt-in because application exception messages may contain sensitive data.

Every retry claims the same occurrence ID and retains the same key.

The sequence has already advanced after durable local handoff. A failed occurrence therefore does not silently move scheduling state backward. Failed occurrences remain queryable and prevent automatic sequence pruning.

The package does not infer that a successful PHP return means an external system performed exactly one side effect. External integrations should use occurrence keys as idempotency keys where available.

## Abandoned claims

A worker can die after moving an occurrence to `running`. The minute runner finds claims older than `abandoned_after_seconds`, returns them to `pending`, and republishes them.

This timeout must exceed normal handler duration. Long-running business work should be dispatched to a dedicated application job.

## Downtime behavior

Three policies are implemented:

| Policy | Contract |
| --- | --- |
| `coalesce_latest` | Create one occurrence for the latest overdue logical position |
| `replay_all` | Materialize overdue positions in order, bounded per run |
| `skip` | Advance beyond overdue positions without executing them |

The default is `coalesce_latest`, matching reminder-style workflows and preventing a burst after downtime.

`replay_all` is bounded by `replay_limit`; additional overdue work remains due for the next runner pass.

## Time anchoring

Recurrence is calculated from the intended `scheduled_at`, not actual worker completion time.

If an occurrence intended for 10:00 executes at 10:07, a five-day recurrence remains anchored to 10:00. Queue latency does not cause permanent schedule drift.

For `$repeatEvery`, missed intervals follow the selected catch-up policy. For `$repeatSequence`, the next cycle anchors to the previous cycle's final scheduled occurrence.

## Lifecycle

```text
active sequence
      │
      ├─ due → occurrence pending → published → running → succeeded
      │                                  │          ├─ failed/retry
      │                                  │          ├─ stale
      │                                  │          └─ cancelled
      │                                  ▼
      ├─ more schedule → active with new next_at
      ├─ final handoff → completed
      └─ shouldContinue false / explicit cancel → cancelled
```

Terminal sequence rows remain temporarily available for stale-job validation. Non-permanent rows are pruned after the configured retention period when no unresolved occurrence remains. `$rememberPermanently = true` keeps the business record and its memory.

## Automatic runner registration

The provider registers:

```php
Schedule::command('scheduled-sequence:run')
    ->everyMinute()
    ->withoutOverlapping();
```

This does not install an operating-system cron entry. Production still needs Laravel's documented scheduler infrastructure.

Registration is configurable because applications may prefer to own the event definition.

## Compatibility

The reference package retains adapters for its earlier API:

- `init()` delegates to `start()`;
- `$repeatEveryAfterLastOffset` aliases `$repeatEvery`;
- `$recurrence` aliases `$repeatSequence`;
- `onExpiredOffset` and `on{normalizedOffset}` run through the default `handle` implementation.

The earlier `shouldContinue(ScheduledSequence)` signature must change to `shouldContinue(Occurrence)` because PHP cannot safely adapt an overridden incompatible parameter type.

Published original migrations are not modified. Reliability state and occurrence storage use additive migrations.

## Acceptance scenarios

The reference tests cover:

1. repeated runner discovery creates one occurrence;
2. pending work survives until a publisher runs;
3. duplicate jobs execute application handling once;
4. fast synchronous execution is not regressed by publisher status updates;
5. restart/version change makes old occurrences stale;
6. explicit cancellation makes queued work stale;
7. retries keep one occurrence identity;
8. abandoned running claims are recovered;
9. final occurrences execute after scheduling status becomes completed;
10. coalesce, replay, skip, recurrence, and time anchoring;
11. permanent memory and delayed terminal cleanup;
12. automatic Laravel Scheduler registration.

## Remaining limits

- Database row locking behavior depends on the selected database engine.
- External side effects remain at-least-once unless the destination supports idempotency.
- A process may be terminated during an external side effect before local success is recorded.
- Handler definitions are PHP code; changing offsets does not automatically migrate active definitions until they are restarted.
- Timezone and daylight-saving policy should be chosen deliberately for local-clock offsets.

These limits are explicit boundaries rather than hidden consequences of `next_at` update order.
