<?php

namespace AiSoft\ScheduledSequence\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate an application scheduled-sequence handler.
 */
class MakeScheduledSequenceCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:scheduled-sequence';

    /** @var string */
    protected $description = 'Create a new scheduled sequence class';

    /** @var string */
    protected $type = 'Scheduled sequence';

    /**
     * Get the generator stub path.
     */
    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/scheduled-sequence.stub';
    }

    /**
     * Get the default namespace for generated handlers.
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\ScheduledSequence';
    }

    /**
     * Get the command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
        ];
    }
}
