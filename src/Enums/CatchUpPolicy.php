<?php

namespace AiSoft\ScheduledSequence\Enums;

use InvalidArgumentException;

/**
 * Supported policies for occurrences that became due while the runner was unavailable.
 */
final class CatchUpPolicy
{
    public const COALESCE_LATEST = 'coalesce_latest';

    public const REPLAY_ALL = 'replay_all';

    public const SKIP = 'skip';

    /**
     * Validate and return a configured catch-up policy.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $policy): string
    {
        if (! in_array($policy, self::values(), true)) {
            throw new InvalidArgumentException("Invalid scheduled sequence catch-up policy [{$policy}].");
        }

        return $policy;
    }

    /**
     * Return all supported catch-up policies.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::COALESCE_LATEST, self::REPLAY_ALL, self::SKIP];
    }
}
