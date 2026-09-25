# Laravel Scheduled Sequence

> **Experimental reference implementation**
>
> Persistent, state-aware scheduling sequences with irregular timing for Laravel applications.

This package explores a first-class abstraction for **persistent irregular schedules whose timing belongs to an individual application entity**, rather than to the application's global schedule or to delayed jobs stored in a queue.

It is the reference implementation for [Laravel Framework Discussion #61689](https://github.com/laravel/framework/discussions/61689).

## Why

Laravel already has excellent primitives for:

- application-level recurring schedules via the Scheduler;
- deferred execution via queues and delayed jobs.

What is missing is an explicit representation of long-lived scheduling state such as:

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

Each entity may start independently, and the sequence may stop when current application state changes.

## Core idea

```text
Application entity
        │
        ▼
     start_at
        │
        ▼
 irregular offsets
        │
        ▼
      next_at
        │
        ▼
 shouldContinue()
      /      \
    yes       no
     │         │
 handle()    cancel
     │
     ▼
 next occurrence
```

The important distinction is not only *how work is executed later*, but **where the authoritative scheduling state lives**.

## Example API

> The API below is illustrative and may change.

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
        // Perform or dispatch work for this occurrence.
    }
}
```

Start it for an entity:

```php
AccountLeftUnpaidSequence::start($account);
```

## Relationship to Laravel Scheduler and queues

| Mechanism | Responsibility |
| --- | --- |
| Laravel Scheduler | Determines when the application checks for due work |
| Queue / delayed jobs | Executes or defers an individual unit of work |
| Scheduled Sequence | Owns persistent, entity-specific temporal state between occurrences |

A sequence runner may itself be scheduled normally:

```php
Schedule::command('scheduled-sequence:run')
    ->everyMinute()
    ->withoutOverlapping();
```

A sequence occurrence may dispatch normal queued work:

```php
protected function handle(Occurrence $occurrence): void
{
    SendAccountReminder::dispatch($this->model);
}
```

## Design status

The package is intentionally experimental. The public contract is still being refined, especially around:

- occurrence identity and definition versioning;
- dispatch timing vs. action-completion semantics;
- duplicate claims by multiple runners;
- stale queued work after cancellation or rescheduling;
- durable handoff between database state and external queues;
- downtime and missed-occurrence policies;
- retries, backoff and idempotency boundaries.

See [docs/RFC.md](docs/RFC.md) for the full design document.

## Non-goals

Scheduled Sequences are not intended to:

- replace Laravel's Scheduler;
- replace queues, chains, or batches;
- become a general workflow/BPM engine;
- model arbitrary branching graphs;
- guarantee exactly-once external side effects;
- store every future occurrence in advance.

The topology stays linear:

```text
A → B → C → D → D → D ...
```

## Current target

The reference package is intended to support Laravel 10–13 while the API evolves.

## Installation

The package is **not yet published on Packagist**.

For now, this repository should be treated as a reference implementation and design workspace.

## Contributing

Design feedback is welcome, especially on the contracts documented in the RFC and the open reliability issues.

Please also see the upstream Laravel discussion:

- https://github.com/laravel/framework/discussions/61689

## License

MIT. See [LICENSE](LICENSE).
