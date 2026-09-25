<?php

use AiSoft\ScheduledSequence\Enums\CatchUpPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table(config('scheduled-sequence.table', 'scheduled_sequences'), function (Blueprint $table): void {
            $table->unsignedBigInteger('definition_version')->default(1)->after('status');
            $table->unsignedBigInteger('next_occurrence_number')->default(1)->after('definition_version');
            $table->string('catch_up_policy')->default(CatchUpPolicy::COALESCE_LATEST)->after('next_occurrence_number');
        });
    }

    public function down(): void
    {
        Schema::table(config('scheduled-sequence.table', 'scheduled_sequences'), function (Blueprint $table): void {
            $table->dropColumn(['definition_version', 'next_occurrence_number', 'catch_up_policy']);
        });
    }
};
