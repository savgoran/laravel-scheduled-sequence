<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Support;

use RuntimeException;

final class ProcessBarrier
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create process barrier directory [{$directory}].");
        }
    }

    /**
     * Signal that a process reached a named barrier.
     *
     * @param array<string, mixed> $data
     */
    public function signal(string $name, array $data = []): void
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path($name), $payload, LOCK_EX) === false) {
            throw new RuntimeException("Unable to signal process barrier [{$name}].");
        }
    }

    /**
     * Release processes waiting on a named barrier.
     */
    public function release(string $name): void
    {
        $this->signal("release-{$name}");
    }

    /**
     * Wait until another process signals a named barrier.
     *
     * @return array<string, mixed>
     */
    public function waitFor(string $name, float $timeoutSeconds = 15): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $path = $this->path($name);

        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for process barrier [{$name}].");
            }

            usleep(10_000);
        }

        $contents = file_get_contents($path);

        return $contents === false || $contents === ''
            ? []
            : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Wait until the controlling process releases a named barrier.
     */
    public function waitForRelease(string $name, float $timeoutSeconds = 30): void
    {
        $this->waitFor("release-{$name}", $timeoutSeconds);
    }

    public function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name.'.json';
    }
}
