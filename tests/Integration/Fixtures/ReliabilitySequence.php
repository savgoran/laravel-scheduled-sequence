<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Fixtures;

use AiSoft\ScheduledSequence\Occurrence;
use AiSoft\ScheduledSequence\ScheduledSequence;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ProcessBarrier;
use Illuminate\Support\Facades\DB;

class ReliabilitySequence extends ScheduledSequence
{
    protected array $offsets = ['now'];

    protected function handle(Occurrence $occurrence): void
    {
        DB::table('reliability_deliveries')->insert([
            'occurrence_key' => $occurrence->key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reliability_effects')->insertOrIgnore([
            'occurrence_key' => $occurrence->key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $directory = getenv('RELIABILITY_EFFECT_BARRIER');
        if ($directory === false || $directory === '') {
            return;
        }

        $barrier = new ProcessBarrier($directory);
        $barrier->signal('effect-applied', ['occurrence_key' => $occurrence->key]);
        $barrier->waitForRelease('effect-applied');
    }
}
