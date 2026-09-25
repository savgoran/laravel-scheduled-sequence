<?php

use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;
use AiSoft\ScheduledSequence\Models\ScheduledSequence;
use AiSoft\ScheduledSequence\Models\ScheduledSequenceOccurrence;

return [
    'timezone' => env('SCHEDULED_SEQUENCE_TIMEZONE', config('app.timezone', 'UTC')),
    'model' => ScheduledSequence::class,
    'occurrence_model' => ScheduledSequenceOccurrence::class,
    'table' => 'scheduled_sequences',
    'occurrences_table' => 'scheduled_sequence_occurrences',
    'catch_up_policy' => CatchUpPolicy::COALESCE_LATEST,
    'replay_limit' => 100,
    'catch_up_scan_limit' => 10000,
    'run_limit' => 100,
    'publish_limit' => 100,
    'queue_connection' => null,
    'queue' => null,
    'queue_tries' => 3,
    'queue_backoff' => [60, 300, 900],
    'store_exception_messages' => false,
    'abandoned_after_seconds' => 900,
    'terminal_retention_seconds' => 604800,
    'register_scheduler' => true,
    'lock_seconds' => 60,
];
