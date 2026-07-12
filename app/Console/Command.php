<?php
declare(strict_types=1);

namespace App\Console;

/** A console command. Implementations do their work in run() and return a Unix exit code. */
interface Command
{
    /** Invocation name, e.g. "queue:work". */
    public function name(): string;

    /** One-line description for the command list. */
    public function description(): string;

    /**
     * @param list<string> $args positional args after the command name.
     * @return int exit code (0 = ok).
     */
    public function run(array $args): int;
}
