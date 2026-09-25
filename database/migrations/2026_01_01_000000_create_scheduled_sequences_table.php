<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create(config('scheduled-sequence.table', 'scheduled_sequences'), function (Blueprint $table): void {
            $table->id();
            $table->string('handler');
            $table->morphs('sequenceable');
            $table->string('owner_id')->nullable()->index();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->dateTime('next_at')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->json('memory')->nullable();
            $table->timestamps();
            $table->unique(
                ['handler', 'sequenceable_id', 'sequenceable_type'],
                'scheduled_sequences_handler_sequenceable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('scheduled-sequence.table', 'scheduled_sequences'));
    }
};
