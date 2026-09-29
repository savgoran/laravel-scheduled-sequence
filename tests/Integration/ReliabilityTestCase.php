<?php

namespace AiSoft\ScheduledSequence\Tests\Integration;

use AiSoft\ScheduledSequence\ScheduledSequenceServiceProvider;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ProcessBarrier;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ReliabilityEnvironment;
use AiSoft\ScheduledSequence\Tests\Integration\Support\ReliabilitySchema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

abstract class ReliabilityTestCase extends TestCase
{
    /** @var list<string> */
    private array $barrierDirectories = [];

    protected function setUp(): void
    {
        if (getenv('RUN_RELIABILITY_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_RELIABILITY_TESTS=1 to run database reliability tests.');
        }

        parent::setUp();
        ReliabilitySchema::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->barrierDirectories as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ScheduledSequenceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        ReliabilityEnvironment::configure($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        // ReliabilitySchema creates the shared schema after Testbench boots.
    }

    protected function newBarrier(): ProcessBarrier
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduled-sequence-'.bin2hex(random_bytes(8));
        $this->barrierDirectories[] = $directory;

        return new ProcessBarrier($directory);
    }

    /**
     * Start a standalone Testbench worker.
     *
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    protected function startWorker(
        string $action,
        array $arguments = [],
        array $environment = [],
    ): Process {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/bin/worker.php', $action, ...$arguments],
            dirname(__DIR__, 2),
            array_replace(ReliabilityEnvironment::processEnvironment(), $environment),
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    protected function waitForWorker(Process $process): void
    {
        $exitCode = $process->wait();

        $this->assertSame(
            0,
            $exitCode,
            trim($process->getErrorOutput().PHP_EOL.$process->getOutput()),
        );
    }

    protected function stopWorker(Process $process): void
    {
        if ($process->isRunning()) {
            $process->stop(0, 9);
        }
    }

    protected function waitForBarrier(
        ProcessBarrier $barrier,
        string $name,
        Process ...$processes,
    ): array {
        $deadline = microtime(true) + 15;
        $path = $barrier->path($name);

        while (! is_file($path)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail(trim($process->getErrorOutput().PHP_EOL.$process->getOutput()));
                }
            }

            if (microtime(true) >= $deadline) {
                $this->fail("Timed out waiting for process barrier [{$name}].");
            }

            usleep(10_000);
        }

        return $barrier->waitFor($name, 0.1);
    }
}
