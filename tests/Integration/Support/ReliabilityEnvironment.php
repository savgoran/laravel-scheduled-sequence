<?php

namespace AiSoft\ScheduledSequence\Tests\Integration\Support;

use ReflectionProperty;

final class ReliabilityEnvironment
{
    /**
     * Configure a Testbench application for the shared reliability database.
     */
    public static function configure($app): void
    {
        $namespace = new ReflectionProperty($app, 'namespace');
        $namespace->setAccessible(true);
        $namespace->setValue($app, 'Workbench\\App\\');

        $app['config']->set('database.default', 'reliability');
        $app['config']->set('database.connections.reliability', self::databaseConfiguration());
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'reliability');
        $app['config']->set('queue.connections.reliability', [
            'driver' => 'database',
            'connection' => 'reliability',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 1,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.connections.barrier', [
            'driver' => 'barrier-database',
            'connection' => 'reliability',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 1,
            'after_commit' => false,
        ]);
        $app['config']->set('scheduled-sequence.queue_connection', 'reliability');
        $app['config']->set('scheduled-sequence.abandoned_after_seconds', 1);
        $app['config']->set('scheduled-sequence.timezone', 'UTC');
    }

    /**
     * Return the configured MySQL or PostgreSQL connection.
     *
     * @return array<string, mixed>
     */
    public static function databaseConfiguration(): array
    {
        $driver = self::value('RELIABILITY_DB_CONNECTION', 'mysql');

        $configuration = [
            'driver' => $driver,
            'host' => self::value('RELIABILITY_DB_HOST', '127.0.0.1'),
            'port' => self::value(
                'RELIABILITY_DB_PORT',
                $driver === 'pgsql' ? '5432' : '3306',
            ),
            'database' => self::value('RELIABILITY_DB_DATABASE', 'scheduled_sequence'),
            'username' => self::value(
                'RELIABILITY_DB_USERNAME',
                $driver === 'pgsql' ? 'postgres' : 'root',
            ),
            'password' => self::value('RELIABILITY_DB_PASSWORD', 'password'),
            'prefix' => '',
        ];

        if ($driver === 'pgsql') {
            return $configuration + [
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ];
        }

        return $configuration + [
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'engine' => null,
        ];
    }

    /**
     * Export database settings to a child process without logging their values.
     *
     * @return array<string, string>
     */
    public static function processEnvironment(): array
    {
        $environment = ['RUN_RELIABILITY_TESTS' => '1'];

        foreach ([
            'RELIABILITY_DB_CONNECTION',
            'RELIABILITY_DB_HOST',
            'RELIABILITY_DB_PORT',
            'RELIABILITY_DB_DATABASE',
            'RELIABILITY_DB_USERNAME',
            'RELIABILITY_DB_PASSWORD',
        ] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private static function value(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
