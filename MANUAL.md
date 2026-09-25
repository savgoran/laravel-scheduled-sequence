# Scheduled Sequence usage manual

This manual builds an unpaid-account reminder as a durable, model-specific temporal sequence.

## 1. Install and migrate

```bash
composer require aisoft/laravel-scheduled-sequence
php artisan vendor:publish --tag=scheduled-sequence-config
php artisan vendor:publish --tag=scheduled-sequence-migrations
php artisan migrate
```

For a local path repository:

```json
{
    "repositories": [
        {"type": "path", "url": "../package", "options": {"symlink": true}}
    ],
    "require": {
        "aisoft/laravel-scheduled-sequence": "@dev"
    }
}
```

The package creates:

- `scheduled_sequences`, containing current model-specific scheduling state;
- `scheduled_sequence_occurrences`, containing durable due-work records and their execution state.

## 2. Generate a handler

```bash
php artisan make:scheduled-sequence AccountLeftUnpaidSequence
```

Generated handlers use the domain-neutral `Occurrence` API:

```php
<?php

namespace App\ScheduledSequence;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use App\Jobs\SendAccountReminder;
use App\Models\Account;

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

    protected bool $rememberPermanently = true;

    protected function shouldContinue(Occurrence $occurrence): bool
    {
        $account = $occurrence->sequence->sequenceable;

        return $account instanceof Account && $account->isUnpaid();
    }

    protected function handle(Occurrence $occurrence): void
    {
        $kind = match ($occurrence->offset) {
            'now' => 'initial',
            default => 'reminder',
        };

        SendAccountReminder::dispatch(
            accountId: $occurrence->sequence->sequenceable_id,
            kind: $kind,
            occurrenceKey: $occurrence->key,
        );

        $occurrence->sequence->remember(
            "dispatches.{$occurrence->number}.queued_at",
            now()->toIso8601String(),
        );
    }
}
```

`match` supports a `default` arm, so one offset can have special behavior while all other offsets share the same action.

Offsets are relative to `start_at`, use PHP `DateTime::modify` syntax, and are normalized for storage and compatibility callbacks. They must be unique after normalization and strictly ordered.

## 3. Start, restart, and cancel

Start a sequence:

```php
$sequence = AccountLeftUnpaidSequence::start(
    originModel: $account,
    ownerId: $account->user_id,
);
```

Start at a custom time:

```php
AccountLeftUnpaidSequence::start(
    $account,
    $account->user_id,
    now()->startOfDay(),
);
```

The handler/model pair is unique. Calling `start` again restarts the same database record, increments `definition_version`, resets its position, and makes occurrences from the older version stale.

Cancel explicitly:

```php
$sequence->getSequenceRecord()->markCancelled();
```

`init` remains a deprecated alias for `start`.

## 4. Execution flow

Every minute, `scheduled-sequence:run` performs four recoverable operations:

1. recover occurrence claims abandoned by crashed workers;
2. atomically materialize due occurrences and advance their sequences;
3. publish pending occurrences to Laravel Queue;
4. prune eligible terminal sequences.

Materialization locks the sequence row and writes the sequence transition and occurrence in one database transaction. The occurrence has a unique identity:

```text
scheduled-sequence:{sequence_id}:{definition_version}:{occurrence_number}
```

The string format is internal. Treat `$occurrence->key` as opaque.

The package execution job atomically claims the occurrence. Duplicate queue publications are safe because only one job can move it into `running` state.

Before application code runs, the job:

- reloads the sequence;
- compares its definition version;
- rejects explicitly cancelled sequences;
- invokes `shouldContinue` using current application state.

`completed` is valid for the final occurrence of the matching definition: scheduling may be complete while its final queued execution is still pending.

## 5. Scheduler and workers

The service provider registers:

```php
Schedule::command('scheduled-sequence:run')
    ->everyMinute()
    ->withoutOverlapping();
```

The server still needs Laravel's normal scheduler process or cron entry:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Start a worker for the configured queue:

```bash
php artisan queue:work
```

Relevant configuration:

```php
'queue_connection' => null, // Laravel default when null
'queue' => null,
'queue_tries' => 3,
'queue_backoff' => [60, 300, 900],
'store_exception_messages' => false,
'abandoned_after_seconds' => 900,
'register_scheduler' => true,
```

Set `register_scheduler` to `false` only when the application registers the runner manually.

## 6. Dispatching an application job

The package occurrence job considers `handle` successful after it returns. If `handle` dispatches another queued job, execution and retries of that job belong to Laravel Queue.

Use an asynchronous queue connection when occurrence retries are required. Laravel's `sync` connection executes inside the runner and has no worker retry cycle.

Pass the occurrence key to delayed application work:

```php
SendAccountReminder::dispatch(
    accountId: $account->getKey(),
    occurrenceKey: $occurrence->key,
);
```

Revalidate immediately before the external side effect:

```php
use AiSoft\ScheduledSequence\Services\OccurrenceGuard;

public function handle(OccurrenceGuard $guard): void
{
    if (! $guard->allows($this->occurrenceKey)) {
        return;
    }

    $this->sendReminder(
        idempotencyKey: $this->occurrenceKey,
    );
}
```

`OccurrenceGuard` rejects cancelled or superseded sequence versions and can re-run the sequence's business condition. Pass `false` as its second argument only when the application intentionally wants version/status validation without `shouldContinue`.

No process can guarantee exactly-once behavior in an external system without cooperation from that system. Use `$occurrence->key` as an idempotency key where supported.

## 7. Catch-up policies

Downtime behavior is explicit:

```php
use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;

protected ?string $catchUpPolicy = CatchUpPolicy::COALESCE_LATEST;
```

Available policies:

| Policy | Behavior |
| --- | --- |
| `COALESCE_LATEST` | Create one occurrence for the latest overdue position |
| `REPLAY_ALL` | Create each overdue occurrence, bounded by `replay_limit` |
| `SKIP` | Advance past overdue work without creating occurrences |

The default is `COALESCE_LATEST`.

For regular recurrence after a finite prefix:

```php
protected ?string $repeatEvery = '5 days';
```

For repeating the entire offset sequence:

```php
protected bool $repeatSequence = true;
```

Both recurrence modes remain anchored to intended scheduled time, so queue delay does not shift all future dates.

## 8. Occurrence states and failures

Occurrence states are:

```text
pending -> published -> running -> succeeded
                              \-> failed
                              \-> cancelled
                              \-> stale
```

The queue retries a failed occurrence using the same occurrence ID and key. `attempts` and a bounded `last_error` are stored for diagnosis. By default `last_error` stores only the exception class; storing exception messages must be enabled explicitly because messages may contain sensitive application data.

If a worker dies after claiming work, the runner returns the occurrence to `pending` after `abandoned_after_seconds`.

Failed occurrences are not automatically pruned. Resolve or retain them as part of the application's operational policy.

## 9. Memory and permanent retention

The sequence model stores small JSON markers:

```php
$record->remember('notices.initial.sent_at', now()->toIso8601String());
$record->hasMemory('notices.initial.sent_at');
$record->recall('notices.initial.sent_at');
$record->forgetMemory('notices.initial.sent_at');
```

Enable permanent retention for an audit record:

```php
protected bool $rememberPermanently = true;
```

Otherwise, completed or cancelled records remain for `terminal_retention_seconds`, allowing delayed jobs to validate state, and are then pruned when no pending, published, running, or failed occurrence remains.

## 10. Inspecting state

```php
use AiSoft\ScheduledSequence\Models\ScheduledSequence;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;

$active = ScheduledSequence::query()
    ->where('status', ScheduledSequence::STATUS_ACTIVE)
    ->orderBy('next_at')
    ->get();

$failed = ScheduledSequenceOccurrence::query()
    ->where('status', ScheduledSequenceOccurrence::STATUS_FAILED)
    ->orderByDesc('finished_at')
    ->get();
```

Never expose `last_error` directly to end users without filtering; it is operational metadata.

## 11. Testing

Use `Carbon::setTestNow` and the synchronous queue for deterministic tests:

```php
Carbon::setTestNow('2026-01-01 10:00:00');
$sequence = AccountLeftUnpaidSequence::start($account);

$this->artisan('scheduled-sequence:run')->assertSuccessful();

$this->assertDatabaseHas('scheduled_sequence_occurrences', [
    'scheduled_sequence_id' => $sequence->getSequenceRecord()->getKey(),
    'status' => 'succeeded',
]);
```

Also test cancellation after publication, restart/reschedule after publication, duplicate jobs, retry identity, downtime policy, and terminal retention.

See [UPGRADE.md](UPGRADE.md) when migrating handlers written for the earlier direct-callback implementation.
