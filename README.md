# Laravel Scheduled Sequence

> **Experimental reference implementation**
>
> The public API may change while the scheduling and failure semantics are refined.

Database-backed, model-aware scheduled sequences for Laravel 10–13 and PHP 8.1 or newer.

A sequence stores authoritative scheduling state outside the queue. Every due position becomes a durable occurrence with a stable identity before Laravel Queue executes it.

This package is the reference implementation for
[Laravel Framework Discussion #61689](https://github.com/laravel/framework/discussions/61689).

## Install

The package is not yet published on Packagist. Install the experimental branch
directly from GitHub:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/savgoran/laravel-scheduled-sequence"
        }
    ],
    "require": {
        "aisoft/laravel-scheduled-sequence": "dev-main"
    }
}
```

Then publish and run the package migrations:

```bash
php artisan vendor:publish --tag=scheduled-sequence-config
php artisan vendor:publish --tag=scheduled-sequence-migrations
php artisan migrate
```

Package discovery registers the provider and commands. Publishing configuration is optional. Publishing and running the migrations is required.

For local path development:

```json
{
    "repositories": [{"type": "path", "url": "../package"}],
    "require": {"aisoft/laravel-scheduled-sequence": "@dev"}
}
```

## Create a sequence

```bash
php artisan make:scheduled-sequence AccountLeftUnpaidSequence
```

```php
<?php

namespace App\ScheduledSequence;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use App\Jobs\SendAccountReminder;

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
            accountId: $occurrence->sequence->sequenceable_id,
            occurrenceKey: $occurrence->key,
        );
    }
}
```

Start or restart it for a model:

```php
AccountLeftUnpaidSequence::start($account, $account->user_id);
```

Restarting the same handler/model pair increments `definition_version`. Queued occurrences from the older definition become stale and do not execute.

Offsets use formats accepted by PHP `DateTime::modify`, must be unique after normalization, and must move forward in their declared order.

## Execution contract

Scheduled Sequence owns **when work becomes due and when it is durably handed off**. Laravel Queue owns execution attempts and retry timing after handoff.

The runner:

1. locks a due sequence row in a database transaction;
2. creates a uniquely identified occurrence;
3. advances the sequence in the same transaction;
4. publishes the pending occurrence to Laravel Queue;
5. recovers pending or abandoned work on later runs.

Occurrence identity is stable across retries:

```text
(sequence_id, definition_version, occurrence_number)
```

`$occurrence->key` is an opaque idempotency key suitable for passing to application jobs and external integrations.

Before `handle` runs, the package reloads the sequence, validates its definition version and cancellation status, and calls `shouldContinue`. A cancelled, restarted, or rejected occurrence is skipped.

Exactly-once external effects are not promised. Use the occurrence key with integrations that support idempotency.

## Runner registration

The provider registers this command with Laravel Scheduler every minute by default:

```bash
php artisan scheduled-sequence:run
```

The server still needs Laravel's normal scheduler trigger:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Set `register_scheduler` to `false` in the published configuration when the application wants to register the command itself.

Run queue workers for the configured queue connection. With the `sync` queue connection, occurrences execute inside the runner process and Laravel does not provide asynchronous retry attempts.

## Catch-up and recurrence

The default catch-up policy is `coalesce_latest`: when several positions became due during downtime, one occurrence is created for the latest due position.

Per sequence, choose:

```php
use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;

protected ?string $catchUpPolicy = CatchUpPolicy::COALESCE_LATEST;
// CatchUpPolicy::REPLAY_ALL
// CatchUpPolicy::SKIP
```

`REPLAY_ALL` is bounded by `replay_limit`. Recurrence remains anchored to intended scheduled time rather than worker completion time.

Repeat after the finite prefix:

```php
protected ?string $repeatEvery = '5 days';
```

Repeat the complete offset sequence:

```php
protected bool $repeatSequence = true;
```

## Guard application jobs

When `handle` dispatches another job that may wait independently, pass the occurrence key and validate it immediately before its side effect:

```php
use AiSoft\ScheduledSequence\Services\OccurrenceGuard;

public function handle(OccurrenceGuard $guard): void
{
    if (! $guard->allows($this->occurrenceKey)) {
        return;
    }

    // Perform the external side effect with the same idempotency key.
}
```

## Memory and retention

Store small application-owned markers in the sequence record:

```php
$record = $sequence->getSequenceRecord();
$record->remember('notices.initial.sent_at', now()->toIso8601String());
$record->recall('notices.initial.sent_at');
```

Keep terminal state permanently when it is part of the business audit trail:

```php
protected bool $rememberPermanently = true;
```

Non-permanent terminal sequences are retained temporarily so queued jobs can validate their version and status, then pruned after `terminal_retention_seconds`. Failed occurrences block automatic pruning for diagnosis.

## Documentation

- [Usage manual](MANUAL.md)
- [Technical RFC](docs/RFC.md)
- [Implementation plan and acceptance scenarios](docs/IMPLEMENTATION_PLAN.md)
- [Upgrade guide](UPGRADE.md)

## Versioning

This package follows Semantic Versioning. Until `1.0.0`, the public API is
unstable and may change between minor releases. Laravel compatibility is
declared independently through Composer constraints.

## Test

```bash
composer install
composer check
```

`composer check` validates the optimized PSR-4 autoloader, checks formatting with
the PSR-12 preset (the current replacement for PSR-2), and runs PHPUnit.

## License

Laravel Scheduled Sequence is open-source software licensed under the
[MIT license](LICENSE).
