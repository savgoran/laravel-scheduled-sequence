<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ReliabilitySchema
{
    /**
     * Recreate package and reliability-test tables in dependency order.
     */
    public static function reset(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'reliability_effects',
            'reliability_deliveries',
            'jobs',
            'scheduled_sequence_occurrences',
            'scheduled_sequences',
            'test_origins',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('test_origins', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        foreach ([
            __DIR__.'/../../../database/migrations/2026_01_01_000000_create_scheduled_sequences_table.php',
            __DIR__.'/../../../database/migrations/2026_09_25_000001_add_execution_state_to_scheduled_sequences_table.php',
            __DIR__.'/../../../database/migrations/2026_09_25_000002_create_scheduled_sequence_occurrences_table.php',
        ] as $migrationPath) {
            $migration = require $migrationPath;
            $migration->up();
        }

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('reliability_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('occurrence_key')->index();
            $table->timestamps();
        });

        Schema::create('reliability_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('occurrence_key')->unique();
            $table->timestamps();
        });
    }
}
