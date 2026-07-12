<?php
declare(strict_types=1);

namespace App\Console;

/**
 * Tiny console kernel: a registry of Command objects dispatched by name.
 * setup/bin/console bootstraps once, registers commands, and routes. Pure
 * (returns exit codes, writes to an injectable stream) so it's testable
 * without a subprocess.
 */
final class Kernel
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function register(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /** @return list<string> registered command names, sorted. */
    public function names(): array
    {
        $names = array_keys($this->commands);
        sort($names);
        return $names;
    }

    /**
     * @param list<string> $argv full argv ([0] = script).
     * @param resource $out
     * @return int exit code.
     */
    public function handle(array $argv, $out = STDOUT): int
    {
        $name = $argv[1] ?? 'list';
        if (in_array($name, ['list', '--help', '-h', ''], true)) {
            $this->list($out);
            return 0;
        }
        $cmd = $this->commands[$name] ?? null;
        if ($cmd === null) {
            fwrite($out, "Unknown command: {$name}\n\n");
            $this->list($out);
            return 1;
        }
        return $cmd->run(array_slice($argv, 2));
    }

    /** @param resource $out */
    private function list($out): void
    {
        fwrite($out, "Available commands:\n");
        ksort($this->commands);
        foreach ($this->commands as $name => $cmd) {
            fwrite($out, sprintf("  %-26s %s\n", $name, $cmd->description()));
        }
    }
}
