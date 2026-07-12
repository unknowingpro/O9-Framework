<?php
declare(strict_types=1);

namespace Tests\Console;

use App\Console\Command;
use App\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private function fakeCommand(string $name, string $desc, int $exit = 0): Command
    {
        return new class($name, $desc, $exit) implements Command {
            /** @var list<string> */
            public array $received = [];

            public function __construct(private string $n, private string $d, private int $exit)
            {
            }

            public function name(): string { return $this->n; }
            public function description(): string { return $this->d; }

            public function run(array $args): int
            {
                $this->received = $args;
                return $this->exit;
            }
        };
    }

    private function captureOutput(Kernel $kernel, array $argv): array
    {
        $stream = fopen('php://memory', 'w+');
        $exit = $kernel->handle($argv, $stream);
        rewind($stream);
        $out = (string) stream_get_contents($stream);
        fclose($stream);
        return [$exit, $out];
    }

    public function testDispatchesToTheNamedCommandWithRemainingArgs(): void
    {
        $kernel = new Kernel();
        $cmd = $this->fakeCommand('greet', 'Says hi');
        $kernel->register($cmd);

        [$exit, $out] = $this->captureOutput($kernel, ['console', 'greet', 'world', '--loud']);
        $this->assertSame(0, $exit);
        $this->assertSame('', $out);
        $this->assertSame(['world', '--loud'], $cmd->received);
    }

    public function testReturnsTheCommandsExitCode(): void
    {
        $kernel = new Kernel();
        $kernel->register($this->fakeCommand('fail', 'Fails', 3));
        [$exit] = $this->captureOutput($kernel, ['console', 'fail']);
        $this->assertSame(3, $exit);
    }

    public function testUnknownCommandListsAndReturnsOne(): void
    {
        $kernel = new Kernel();
        $kernel->register($this->fakeCommand('greet', 'Says hi'));
        [$exit, $out] = $this->captureOutput($kernel, ['console', 'nope']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown command: nope', $out);
        $this->assertStringContainsString('greet', $out);
    }

    public function testNoArgumentsListsCommands(): void
    {
        $kernel = new Kernel();
        $kernel->register($this->fakeCommand('greet', 'Says hi'));
        [$exit, $out] = $this->captureOutput($kernel, ['console']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Available commands:', $out);
        $this->assertStringContainsString('greet', $out);
        $this->assertStringContainsString('Says hi', $out);
    }

    public function testHelpFlagsListCommands(): void
    {
        $kernel = new Kernel();
        $kernel->register($this->fakeCommand('greet', 'Says hi'));
        foreach (['list', '--help', '-h', ''] as $arg) {
            [$exit, $out] = $this->captureOutput($kernel, ['console', $arg]);
            $this->assertSame(0, $exit, $arg);
            $this->assertStringContainsString('Available commands:', $out, $arg);
        }
    }

    public function testNamesReturnsSortedRegisteredCommandNames(): void
    {
        $kernel = new Kernel();
        $kernel->register($this->fakeCommand('zeta', 'z'));
        $kernel->register($this->fakeCommand('alpha', 'a'));
        $this->assertSame(['alpha', 'zeta'], $kernel->names());
    }
}
