<?php

namespace AiSoft\ScheduledSequence\Tests;

use Illuminate\Support\Facades\File;

class MakeScheduledSequenceCommandTest extends TestCase
{
    public function test_it_creates_a_scheduled_sequence_in_the_default_namespace(): void
    {
        $appPath = $this->app->storagePath('framework/testing/scheduled-sequence-generator-'.uniqid());
        File::ensureDirectoryExists($appPath);
        $this->app->useAppPath($appPath);

        try {
            $this->artisan('make:scheduled-sequence', ['name' => 'TrialReminderSequence'])
                ->assertSuccessful();

            $path = $appPath.'/ScheduledSequence/TrialReminderSequence.php';
            $this->assertFileExists($path);

            $contents = File::get($path);
            $namespace = trim($this->app->getNamespace(), '\\').'\\ScheduledSequence';
            $this->assertStringContainsString("namespace {$namespace};", $contents);
            $this->assertStringContainsString('class TrialReminderSequence extends ScheduledSequence', $contents);
            $this->assertStringContainsString("'1 day 10am'", $contents);
            $this->assertStringContainsString('Define the scheduled actions', $contents);
            $this->assertStringContainsString('protected function shouldContinue(Occurrence $occurrence)', $contents);
            $this->assertStringContainsString('protected function handle(Occurrence $occurrence)', $contents);
        } finally {
            File::deleteDirectory($appPath);
        }
    }

    public function test_it_does_not_overwrite_an_existing_scheduler_without_force(): void
    {
        $appPath = $this->app->storagePath('framework/testing/scheduled-sequence-generator-'.uniqid());
        File::ensureDirectoryExists($appPath.'/ScheduledSequence');
        File::put($appPath.'/ScheduledSequence/ExistingSequence.php', 'original');
        $this->app->useAppPath($appPath);

        try {
            $this->artisan('make:scheduled-sequence', ['name' => 'ExistingSequence'])
                ->assertSuccessful();

            $this->assertSame('original', File::get($appPath.'/ScheduledSequence/ExistingSequence.php'));
        } finally {
            File::deleteDirectory($appPath);
        }
    }
}
