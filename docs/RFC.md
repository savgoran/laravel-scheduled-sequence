# RFC: Scheduled Sequences for Laravel

- **Status:** Draft / experimental
- **Reference implementation:** `aisoft/laravel-scheduled-sequence`
- **Laravel discussion:** https://github.com/laravel/framework/discussions/61689
- **Reference package target:** Laravel 10–13

## 1. Summary

This RFC proposes a small abstraction for **persistent irregular schedules whose timing belongs to an individual application entity**.

A Scheduled Sequence persists its current scheduling state, evaluates application state before each occurrence, and may complete, cancel, or transition into recurrence after an irregular finite prefix.

It complements Laravel's existing primitives:

| Mechanism | Responsibility |
| --- | --- |
| Laravel Scheduler | Determines when the application checks for due work |
| Queue / delayed jobs | Executes or defers an individual unit of work |
| Scheduled Sequence | Owns persistent, entity-specific temporal state between occurrences |

The important distinction is not merely how an action is executed later, but **where the authoritative scheduling state lives**.

## 2. Motivation

Consider a business rule such as:

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

Every account starts independently. Immediately before each occurrence the application must check whether that account still satisfies the condition that keeps the sequence alive.

A cron expression does not naturally represent that state.

Delayed jobs can represent individual future actions, but applications then need to define how cancellation, rescheduling, recurrence, observability, identity and stale delayed work are represented.

A Scheduled Sequence makes that temporal state explicit.

## 3. Goals

- Represent irregular schedules relative to an entity-specific start time.
- Persist only the state required to find the next occurrence.
- Re-evaluate application state before executing or dispatching an occurrence.
- Support finite sequences.
- Support recurrence after a finite irregular prefix.
- Support completion and cancellation.
- Make occurrence identity and failure boundaries explicit.
- Remain compatible with Laravel queues for actual work execution.
- Keep the abstraction linear and smaller than a workflow engine.

## 4. Non-goals

Scheduled Sequences do not aim to:

- replace Laravel's Scheduler;
- replace queues, batches, or chains;
- provide arbitrary branching or joins;
- provide human approval workflows;
- orchestrate general distributed workflows;
- guarantee exactly-once external effects;
- materialize an unbounded list of future jobs.

The topology is always linear:

```text
A → B → C → D → D → D ...
```

## 5. Illustrative API

The following API is illustrative. It exists to make the concept concrete and is not a commitment to a final public API.

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

    protected function shouldContinue(): bool
    {
        return $this->model->isUnpaid();
    }

    protected function handle(Occurrence $occurrence): void
    {
        // Execute or dispatch work.
    }
}
```

Starting a sequence could look like:

```php
AccountLeftUnpaidSequence::start($account);
```

A future `Occurrence` object may expose:

```php
$occurrence->number;
$occurrence->offset;
$occurrence->scheduledAt;
$occurrence->sequenceId;
$occurrence->definitionVersion;
```

## 6. Not notification-specific

Notifications are a useful example but not the abstraction.

A provisioning sequence may require:

```text
today
tomorrow at 09:00
tomorrow at 17:00
5 days later
12 days later
```

The same model applies to provisioning, account lifecycle actions, staged cleanup, customer follow-ups, domain-specific retries, or other linear temporal behavior.

## 7. Persistence model

A reference implementation may persist:

| Column | Purpose |
| --- | --- |
| `id` | Sequence identity |
| `handler` | Class/definition used to reconstruct behavior |
| `sequenceable_type`, `sequenceable_id` | Optional polymorphic owner |
| `start_at` | Anchor used to calculate offsets |
| `next_at` | Indexed next due time |
| `status` | Active, completed, cancelled |
| `definition_version` | Version used to reject superseded work |
| `end_at` | Optional terminal timestamp |
| `memory` | Optional application-owned metadata |

The entire future timeline is derived from the definition and the anchor. It is not stored as an unbounded collection.

## 8. Occurrence identity

Occurrence identity should be stable across retries and queue handoff.

A candidate identity is:

```text
(sequence_id, definition_version, occurrence_number)
```

This identity can be propagated into queued jobs and external idempotency keys.

Example:

```text
scheduled-sequence:123:4:7
```

A stable occurrence identity helps distinguish:

- retrying the same occurrence;
- executing the next occurrence;
- stale work belonging to an older definition;
- duplicate claims by competing runners.

## 9. Definition versioning

Rescheduling or redefining a sequence may invalidate already-dispatched work.

Example:

```text
v1: 1, 3, 7, 15, 20 days
v2: 1, 3, 5, 10 days
```

Queued work created by v1 should not silently remain authoritative after v2 becomes active.

Incrementing `definition_version` on meaningful rescheduling gives workers a clear stale-work check.

## 10. Conditional continuation

Current application state should be evaluated immediately before an occurrence is accepted for processing.

```php
protected function shouldContinue(): bool
{
    return $this->model->status === AccountStatus::Unpaid;
}
```

If the condition is false, the sequence transitions to a terminal state rather than advancing.

However, this check alone does not protect delayed queued work that runs later. See the stale-work contract below.

## 11. Dispatch timing vs action completion

A central contract is what it means for an occurrence to advance.

Two valid models exist.

### 11.1 Dispatch-owned semantics

The sequence owns **dispatch timing**.

```text
occurrence due
    ↓
durably hand off work
    ↓
advance sequence
    ↓
queue/job owns execution retries
```

Under this model, successful durable handoff is the completion point for the scheduler.

This is the preferred direction for the reference implementation because it keeps Scheduled Sequence focused on scheduling rather than turning it into a workflow/orchestration engine.

### 11.2 Completion-owned semantics

The sequence owns **action completion**.

```text
occurrence due
    ↓
dispatch job
    ↓
job executes successfully
    ↓
advance sequence
```

This requires job completion/failure to feed back into the sequence. It is more powerful but materially increases orchestration responsibilities.

The initial design should prefer dispatch-owned semantics unless real usage demonstrates a need for completion-owned mode.

## 12. Stale queued work

Consider:

```text
10:00 occurrence is dispatched
10:10 sequence is cancelled or rescheduled
10:30 queued job starts
```

A scheduler-time `shouldContinue()` check cannot protect the later worker execution.

A queued occurrence should carry its stable identity. Before performing a side effect, the worker may reload the sequence and verify:

- the sequence still exists;
- its status permits execution;
- the expected definition version still matches;
- the occurrence has not already been superseded.

The exact stale-job policy should be explicit and configurable where necessary.

External systems still require their own idempotency guarantees.

## 13. Recurrence after an irregular prefix

Example:

```php
protected array $offsets = [
    '1 day',
    '3 days',
    '7 days',
    '15 days',
    '20 days',
];

protected ?string $repeatEvery = '5 days';
```

represents:

```text
Day 1
Day 3
Day 7
Day 15
Day 20
Day 25
Day 30
Day 35
...
```

The next recurrence should be derived from a documented anchor policy.

## 14. Recurrence anchor policy

Recurring dates can be anchored to:

1. **scheduled time** — each next occurrence is calculated from the previous scheduled occurrence;
2. **actual completion/dispatch time** — each next occurrence is calculated from when work actually finished or was handed off.

The preferred default is **scheduled-time anchoring**, because it preserves the intended calendar cadence and avoids drift caused by queue or application delays.

## 15. Downtime and missed occurrences

Downtime behavior must be explicit.

Possible policies:

- **catch-up** — replay each missed occurrence;
- **skip** — move to the first future occurrence;
- **coalesce** — combine one or more missed occurrences into one execution.

The current production-derived behavior for recurring intervals is closest to **skip to the first future recurrence**.

For finite irregular offsets, the reference implementation should define whether missed occurrences are replayed individually or coalesced. This should be a documented policy rather than an implementation accident.

## 16. Relationship to Laravel Scheduler

The application may schedule a runner normally:

```php
Schedule::command('scheduled-sequence:run')
    ->everyMinute()
    ->withoutOverlapping();
```

The runner queries approximately:

```text
status = active
AND next_at <= now()
```

Laravel Scheduler answers:

> When should the application look for due work?

Scheduled Sequence answers:

> When should this individual entity next have work due?

## 17. Relationship to queues

An occurrence may execute synchronously or dispatch a normal Laravel job.

```php
protected function handle(Occurrence $occurrence): void
{
    SendAccountReminder::dispatch(
        account: $this->model,
        occurrenceId: $occurrence->id(),
    );
}
```

Queues own work execution and job-level retries under dispatch-owned semantics.

## 18. Durable queue handoff

`afterCommit()` prevents a queue worker from observing uncommitted database state, but it does not by itself guarantee publication if the process crashes after database commit and before queue publication completes.

That leaves a failure boundary:

```text
update durable sequence state
        ↓
      COMMIT
        ↓
      crash
        ↓
queue publication never occurs
```

A production-grade reference implementation should make this boundary explicit.

A transactional outbox or equivalent recoverable dispatch record is one valid strategy:

```text
DB transaction
 ├─ persist sequence transition
 └─ persist occurrence/outbox record
        ↓
      COMMIT
        ↓
 recoverable publisher
        ↓
       queue
```

This does not require materializing every future occurrence.

The minimal abstraction need not mandate one outbox implementation, but a package claiming durable dispatch should have a recoverable handoff strategy.

## 19. Concurrency and claiming

Two runners may observe the same due row.

The package must prevent both from independently accepting the same occurrence.

Possible mechanisms include:

- cache locks;
- row locking;
- atomic database claims;
- an occurrence ledger with a unique occurrence key.

Cache locks may reduce overlap but should not be the only correctness mechanism if lock expiry can allow duplicate acceptance.

## 20. Failure semantics

Failure semantics must be explicit.

At minimum, occurrence processing may support:

- **Retry** — keep the same occurrence and retry with backoff;
- **Advance** — record failure and calculate the next occurrence;
- **Cancel** — terminate the sequence.

Example configuration may eventually look like:

```php
protected FailurePolicy $failurePolicy = FailurePolicy::Retry;
protected int $maxAttempts = 5;
protected array $backoff = [60, 300, 900, 3600];
```

Under dispatch-owned semantics, these policies apply to the scheduling/handoff stage. Once a job is durably handed off, execution retry behavior belongs to the job.

## 21. Optional occurrence ledger

An occurrence ledger is not required to express the basic abstraction, but it may simplify robust implementations.

A ledger could store:

| Field | Purpose |
| --- | --- |
| occurrence key | Stable unique identity |
| scheduled_at | Intended time |
| accepted_at | When scheduler claimed it |
| dispatched_at | When work was handed off |
| attempts | Scheduling/handoff attempts |
| status | pending, claimed, dispatched, failed, skipped |
| last_error | Diagnostic metadata |

A unique occurrence key can prevent the scheduler from creating two accepted executions for the same sequence position.

## 22. Memory and retained state

The original production implementation contains optional application-owned JSON memory and terminal-row retention.

Example:

```php
if (! $record->hasMemory('reminder_accepted_at')) {
    $this->sendReminder();
    $record->remember('reminder_accepted_at', now()->toIso8601String());
}
```

This can be useful for domain state and idempotency hints, but it is **not an exactly-once guarantee**.

Memory and permanent retention are secondary capabilities and are not required for the core Scheduled Sequence abstraction.

## 23. Observability

Useful diagnostic fields include:

- sequence ID;
- handler/definition;
- definition version;
- occurrence number/key;
- scheduled time;
- claim time;
- dispatch time;
- attempt number;
- terminal status;
- duration;
- last failure category.

Logs should avoid serializing sensitive domain model data by default.

## 24. Cleanup and retention

Terminal sequence and occurrence rows require an application-defined retention policy.

"Retain" should mean "keep after workflow termination" rather than "never delete".

Applications may prune or archive terminal data according to operational and legal requirements.

## 25. Fault-injection acceptance tests

The reference implementation should explicitly validate at least these cases.

### 25.1 Competing runners

Two runners discover the same due occurrence concurrently.

Expected result: at most one runner accepts the occurrence for dispatch.

### 25.2 Crash across durable state / queue boundary

The process crashes after durable state changes but before queue publication.

Expected result: the handoff is recoverable and the occurrence is not silently lost.

### 25.3 Stale queued work

An occurrence is dispatched, then the sequence is cancelled or rescheduled before the queue worker runs.

Expected result: the worker follows the documented stale-work policy and does not blindly perform an obsolete side effect.

Additional tests should cover:

- worker retry of the same occurrence identity;
- version changes;
- recurring downtime;
- DST transitions;
- missing polymorphic owners;
- long-running handlers;
- idempotency-key propagation.

## 26. Time zones and DST

Offsets containing local clock times require an explicit timezone.

The implementation should distinguish:

- duration-like offsets;
- local calendar times;
- ambiguous/nonexistent local times during DST transitions.

The chosen policy should be deterministic and covered by tests.

## 27. Reference implementation status

The initial implementation grew from production notification/reminder use cases and currently demonstrates:

- relative offsets;
- `next_at` persistence;
- model ownership;
- conditional continuation;
- finite sequences;
- recurrence after the final offset;
- an Artisan runner;
- concurrency protection;
- optional retained state.

Some existing method names are notification-oriented and should be replaced by domain-neutral names such as:

```php
shouldContinue()
handle()
Occurrence
```

Reliability contracts described in this RFC are design targets unless explicitly marked as implemented.

## 28. Open questions

1. Should the core abstraction require Eloquent ownership or support non-model keys?
2. Is `ScheduledSequence` the best name?
3. Should the primary API remain class-based?
4. Should a fluent builder also exist?
5. Should offsets remain date strings, use value objects, or support both?
6. Should dispatch-owned semantics be the only initial contract?
7. Is an occurrence ledger necessary for the first stable release?
8. Which downtime policy should be the default?
9. Should durable outbox-style handoff be built in or exposed as an integration point?
10. How should definition version changes be triggered?
11. Should stale queued work be dropped silently, logged, or surfaced as an event?
12. Should the runner register itself automatically with Laravel Scheduler?

## 29. Adoption path

1. Publish the current package as an experimental reference implementation.
2. Document actual current behavior separately from design targets.
3. Introduce stable occurrence identity and definition versioning.
4. Define dispatch-owned completion semantics.
5. Add stale-work protection.
6. Add fault-injection tests for competing claims and crash boundaries.
7. Define downtime and recurrence anchor policies.
8. Evaluate a durable outbox/occurrence ledger using real workloads.
9. Stabilize domain-neutral public API names.
10. Consider Packagist publication only after the core contract settles.

## 30. Framework question

The package exists to help answer a broader Laravel design question:

> **Would Laravel benefit from a first-class abstraction for persistent irregular schedules whose timing belongs to individual application entities rather than to the application's global schedule?**

The reference implementation should provide evidence through real usage before any framework integration is considered.
