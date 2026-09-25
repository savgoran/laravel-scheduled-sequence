# Upgrade guide

## Durable occurrences release

This release changes execution from direct scheduler callbacks to durable occurrence jobs.

### Publish and run the new migrations

Do not edit an already-published `create_scheduled_sequences_table` migration. Publish the additive migrations:

```bash
php artisan vendor:publish --tag=scheduled-sequence-migrations
php artisan migrate
```

They add definition/version state to `scheduled_sequences` and create `scheduled_sequence_occurrences`.

### Update sequence hooks

New code should use:

```php
use AiSoft\ScheduledSequence\Occurrence;

protected function shouldContinue(Occurrence $occurrence): bool
{
    return true;
}

protected function handle(Occurrence $occurrence): void
{
    // Execute or dispatch this occurrence.
}
```

The old `onExpiredOffset(string, ScheduledSequence)` and `on{normalizedOffset}` callbacks still run through a compatibility adapter. They are deprecated.

The earlier `shouldContinue(ScheduledSequence $sequence)` signature is not compatible with the occurrence-aware API and must be updated.

### Start sequences

Use:

```php
MySequence::start($model, $ownerId);
```

`init` remains as a deprecated alias. Calling `start` again for the same handler/model pair now creates a new definition version and invalidates queued work from the previous version.

### Rename recurrence properties

Use:

```php
protected ?string $repeatEvery = '5 days';
protected bool $repeatSequence = true;
```

`$repeatEveryAfterLastOffset` and `$recurrence` remain compatible aliases.

### Run queue workers

The minute runner now publishes `ExecuteScheduledSequenceOccurrence` jobs. Applications using an asynchronous queue connection must run normal Laravel queue workers.

Configure `queue_connection` and `queue` in `scheduled-sequence.php` when occurrences should use a dedicated queue.

### Remove duplicate scheduler registration

The package automatically schedules `scheduled-sequence:run` every minute by default. Remove a manual registration or set:

```php
'register_scheduler' => false,
```

The operating-system `schedule:run` trigger is still required.

### Account for delayed cleanup

Terminal rows are no longer deleted immediately. They remain available for stale-job validation until `terminal_retention_seconds` expires. Set `$rememberPermanently = true` to exempt a sequence from package pruning.

Failed occurrences are retained and must be inspected or resolved before their sequence can be pruned automatically.
