<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tableName = config(
            'scheduled-sequence.occurrences_table',
            'scheduled_sequence_occurrences',
        );

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_sequence_id');
            $table->unsignedBigInteger('definition_version');
            $table->unsignedBigInteger('occurrence_number');
            $table->string('occurrence_key')->unique();
            $table->string('offset');
            $table->dateTime('scheduled_at');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at')->index();
            $table->dateTime('claimed_at')->nullable()->index();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduled_sequence_id', 'definition_version', 'occurrence_number'],
                'scheduled_sequence_occurrence_identity_unique',
            );
            $table->foreign('scheduled_sequence_id', 'scheduled_sequence_occurrence_sequence_foreign')
                ->references('id')
                ->on(config('scheduled-sequence.table', 'scheduled_sequences'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('scheduled-sequence.occurrences_table', 'scheduled_sequence_occurrences'));
    }
};
