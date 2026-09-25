# Scheduled Sequence reliability implementation plan

## Status

Implemented in the reference package on 2026-09-25.

The package now includes versioned occurrence identity, atomic materialization, a recoverable occurrence outbox, queue execution, stale-work validation, explicit catch-up policies, delayed terminal pruning, automatic runner registration, and the occurrence-aware public API described below.

The test suite exercises competing discovery of the same due ID and the unique database claim. A future database matrix should additionally run simultaneous processes against each supported production database engine to validate engine-specific lock behavior.

## Recommended contract

The package should own **when an occurrence becomes due and when it is durably handed off**. Laravel Queue should own execution attempts and retry timing after that handoff.

The sequence may advance after an occurrence has been persisted to a recoverable occurrence ledger. It should not wait for arbitrary external side effects to complete.

This keeps Scheduled Sequence as a scheduling primitive instead of turning it into a general workflow engine.

Exactly-once external side effects remain out of scope. The package should provide a stable idempotency key that an application can pass to external integrations.

## Baseline gaps addressed by the implementation

1. An occurrence has no stable identity. Handlers receive only a normalized offset string and the sequence instance.
2. Calling `init` again changes the schedule without invalidating work already dispatched for the previous definition.
3. `shouldContinue` runs only in the scheduler process. A queued action may become stale before a worker executes it.
4. The cache lock does not atomically claim database work. Two runners can load the same due row, acquire the lock one after another, and the second runner can execute from its stale model instance.
5. Sequence advancement and dispatch are not a durable handoff. A process crash can lose work or cause the same action to be attempted again.
6. Failure semantics are implicit: an exception leaves `next_at` due, so the scheduler retries on every run.
7. Downtime behavior is partly implemented but not expressed as a policy. The current algorithm coalesces missed offsets into the latest due offset and skips missed recurring intervals.
8. The RFC describes `Occurrence` and `handle`, while the package still exposes `onExpiredOffset` and magic offset methods.
9. The current RFC file ends in the middle of a sentence and needs structural cleanup.

## Target persistence model

### `scheduled_sequences`

Add fields that identify the active definition and its current position:

| Column | Purpose |
| --- | --- |
| `definition_version` | Incremented whenever a sequence is restarted or rescheduled |
| `next_occurrence_number` | Monotonic number assigned to the next logical occurrence |
| `catch_up_policy` | Optional per-sequence override of downtime behavior |

The existing `status`, `start_at`, `next_at`, `end_at`, and `memory` fields remain.

`init` should be replaced by, or delegate to, an explicit `start` operation. Starting an existing handler/model pair must run in a transaction, increment `definition_version`, reset the occurrence number, clear terminal state, and calculate `next_at` atomically.

The current default deletion behavior must be delayed when durable occurrences are enabled. A sequence cannot be deleted while one of its occurrence jobs still needs its version and cancellation state. A non-permanent sequence may be pruned after all occurrences for its final definition are terminal.

### `scheduled_sequence_occurrences`

Introduce a durable occurrence ledger:

| Column | Purpose |
| --- | --- |
| `id` | Internal occurrence ID |
| `scheduled_sequence_id` | Owning sequence |
| `definition_version` | Definition that created the occurrence |
| `occurrence_number` | Monotonic position within that definition |
| `occurrence_key` | Stable public idempotency key |
| `offset` | Normalized configured offset or recurrence marker |
| `scheduled_at` | Intended execution time |
| `status` | `pending`, `published`, `running`, `succeeded`, `failed`, `stale`, or `cancelled` |
| `attempts` | Package-level execution attempts observed |
| `available_at` | Earliest publication/retry time |
| `claimed_at` | Recovery timestamp for abandoned claims |
| `published_at` | Queue publication timestamp |
| `started_at` | Worker start timestamp |
| `finished_at` | Terminal timestamp |
| `last_error` | Sanitized failure summary, with a bounded length |

Add a unique constraint on:

```text
(scheduled_sequence_id, definition_version, occurrence_number)
```

Generate a deterministic public key such as:

```text
scheduled-sequence:{sequence_id}:{definition_version}:{occurrence_number}
```

The exact string format should be treated as opaque by consumers.

## Occurrence API

Add an immutable value object:

```php
final readonly class Occurrence
{
    public function __construct(
        public int|string $sequenceId,
        public int $definitionVersion,
        public int $number,
        public string $key,
        public string $offset,
        public CarbonImmutable $scheduledAt,
    ) {}
}
```

Move handler execution toward:

```php
protected function shouldContinue(Occurrence $occurrence): bool;

protected function handle(Occurrence $occurrence): void;
```

`shouldContinue` must run in the worker immediately before `handle`, after reloading the sequence record.

For a transition period, the package can adapt `handle` to the existing `onExpiredOffset` and `on{offset}` methods. Deprecations should be documented in `UPGRADE.md` before removing the old hooks.

## Atomic due-work claim

Replace the current query-plus-cache-lock flow with a database transaction:

1. Select a due active sequence and lock its row with `lockForUpdate`.
2. Recheck `status`, `next_at`, and the definition version inside the transaction.
3. Insert the occurrence row using the unique identity.
4. Calculate and persist the next sequence state.
5. Commit both changes together.

The unique occurrence constraint is the final duplicate-claim guard. A cache lock may remain as an optimization, but correctness must not depend on it.

The query should explicitly require `status = active`, not only `end_at IS NULL`.

## Durable queue handoff

Use the occurrence row as a recoverable local outbox:

1. The runner creates a `pending` occurrence and advances the sequence in one transaction.
2. A publisher finds pending occurrences and dispatches a package job containing only the occurrence ID.
3. Publication marks the occurrence as `published`.
4. If the publisher crashes before publication, the pending row is found on the next run.
5. If it crashes after publication but before marking the row, it may publish a duplicate job. Both jobs reference the same occurrence, and only one may atomically claim it for execution.

This closes the lost-work gap without claiming exactly-once queue publication.

Provide a package job such as:

```text
ExecuteScheduledSequenceOccurrence
```

The job should atomically transition one occurrence from `pending` or `published` to `running`. A duplicate or terminal occurrence becomes a no-op.

Publisher status updates must use compare-and-set conditions so a fast worker cannot move an occurrence to `running` or `succeeded` and then have the publisher regress it to `published`.

Queue connection, queue name, batch size, and abandoned-claim timeout should be configurable.

## Stale queued work

Before executing `handle`, the package job must reload the sequence and compare:

```text
sequence.definition_version === occurrence.definition_version
sequence.status !== cancelled
```

`completed` is valid for the final occurrence of the matching definition: the sequence may have completed its scheduling responsibility when that occurrence was durably recorded, while the occurrence still awaits queue execution. If the version differs or the sequence was explicitly cancelled, mark the occurrence `stale` or `cancelled` and do not call application code.

If `shouldContinue` returns `false`, atomically cancel the matching sequence definition and skip `handle`. Any already-published later occurrences then fail the same status check.

This protects work that was queued before a sequence was cancelled, restarted, or rescheduled. Application jobs dispatched from `handle` should receive the occurrence key and perform the same validation when they may wait independently in another queue.

Provide a small validator/service so application jobs do not have to reimplement the version and status checks.

## Failure behavior

Under the recommended dispatch-timing contract:

- queue retries apply to the same occurrence identity;
- the sequence has already advanced after the durable local handoff;
- a failed occurrence is visible in the occurrence ledger;
- an optional terminal-failure hook may cancel the remaining sequence, but should not be the default;
- external integrations should use `Occurrence::key` as an idempotency key where supported.

Do not keep the current behavior where every scheduler minute immediately retries an exception with no attempt count or backoff.

The first implementation should use Laravel job attempts and backoff. Package-level retry-policy abstractions can be added only if real use cases require behavior beyond Laravel Queue.

## Downtime and time anchoring

Make catch-up behavior explicit with an enum or value object:

```php
CatchUpPolicy::CoalesceLatest
CatchUpPolicy::Skip
CatchUpPolicy::ReplayAll
```

Recommended default: `CoalesceLatest`, matching the current finite-offset behavior. When multiple occurrences are overdue, create one occurrence for the latest due position and continue from there.

For recurrence after the finite prefix, preserve the current behavior: calculate the first future recurrence and do not replay every missed interval.

Recurring dates should be anchored to the intended `scheduled_at`, not to worker completion time. This prevents queue latency from permanently shifting the schedule.

`ReplayAll` should have a configurable maximum to prevent an unbounded burst after long downtime.

## Commands and scheduling

Keep `scheduled-sequence:run`, but narrow its responsibility to atomically materializing due occurrences and publishing recoverable pending occurrences.

If separation improves recovery and observability, introduce:

```text
scheduled-sequence:run
scheduled-sequence:publish
```

Both operations must be safe to execute concurrently and repeatedly.

Automatic once-per-minute Laravel scheduler registration can be implemented after the execution semantics are stable. It is independent of the operating-system cron configuration.

## Delivery phases

### Phase 1 — Define and test semantics

1. Correct and complete the RFC.
2. State dispatch timing as the package contract.
3. Specify occurrence identity, stale-work behavior, catch-up policy, and scheduled-time anchoring.
4. Add characterization tests for current late-runner and recurrence behavior before refactoring.

### Phase 2 — Occurrence identity and atomic claiming

1. Add the sequence version and occurrence-position fields.
2. Add the occurrence model, table, statuses, unique constraint, and value object.
3. Make `start`/restart atomic and increment the definition version.
4. Replace cache-lock correctness with transactional row claiming.
5. Preserve memory and permanent-retention behavior.

### Phase 3 — Recoverable queue execution

1. Add the occurrence publisher and package execution job.
2. Run `shouldContinue` in the worker after version/status validation.
3. Add duplicate-publication protection and abandoned-claim recovery.
4. Expose the occurrence key to application code.
5. Record bounded execution status and error metadata.

### Phase 4 — Public API alignment

1. Introduce `handle(Occurrence $occurrence)`.
2. Adapt and deprecate `onExpiredOffset` and magic offset callbacks.
3. Introduce `start` while retaining a documented `init` compatibility alias if needed.
4. Update the generator stub and test application to the new API.

### Phase 5 — Operations and documentation

1. Document queue configuration, retention, cleanup, and recovery.
2. Add pruning for terminal occurrence rows.
3. Update README, manual, RFC, and upgrade guide.
4. Add optional automatic scheduler registration only after the runner is idempotent.

## Required acceptance tests

The implementation is not complete until these scenarios pass:

1. **Competing runners:** two runners claim the same due sequence; exactly one occurrence row is created and application handling happens once.
2. **Crash before queue publication:** the sequence and pending occurrence commit, the process stops, and a later publisher recovers the same occurrence.
3. **Duplicate publication:** the same occurrence job is queued twice; only one job claims and handles it.
4. **Stale after cancellation:** an occurrence is published, the sequence is cancelled, and the worker skips it.
5. **Stale after reschedule:** an occurrence is published, the definition version changes, and the old worker skips it.
6. **Stable retry identity:** queue retries retain the same occurrence key.
7. **Finite downtime:** several offsets are missed and the configured catch-up policy determines exactly which occurrences run.
8. **Recurring downtime:** missed recurring intervals do not create an uncontrolled burst and the next date remains anchored to scheduled time.
9. **Permanent memory:** retained completed/cancelled sequences and application memory remain queryable.
10. **Final occurrence:** a sequence marked completed after its final durable handoff still allows that matching occurrence to execute.
11. **Default cleanup:** non-retained terminal sequences are deleted only after their occurrence rows no longer need sequence state, according to the documented pruning policy.

Use two independent database connections or processes for the competing-runner test so the test exercises actual locking behavior rather than sequential mocks.

## Compatibility and migrations

The occurrence ledger and sequence-version columns require package migrations. Published migrations already present in consuming applications must not be edited; release new additive migrations.

Changing handler signatures is a public API break. Prefer a compatibility layer and deprecation period unless the package is explicitly declared pre-release and has no external consumers.

The first release containing durable occurrences should include an upgrade guide covering:

- new migrations;
- queue worker requirements;
- namespace and hook signatures;
- `init` to `start` migration;
- old synchronous behavior;
- occurrence retention and pruning.

## Deliberately deferred work

- fluent sequence definitions;
- arbitrary workflow branching;
- waiting for arbitrary application jobs to report business completion;
- exactly-once external effects;
- storing every future occurrence;
- framework inclusion decisions.

These do not need to be resolved to make the package reliable as a persistent scheduling primitive.
