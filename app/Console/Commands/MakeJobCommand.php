<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;

/**
 * Scaffold a queue job.
 *   php setup/bin/console make:job SendWelcomeEmail
 * "Job" is appended to the class name if not already present.
 */
final class MakeJobCommand implements Command
{
    public function name(): string
    {
        return 'make:job';
    }

    public function description(): string
    {
        return 'Scaffold a queue job, e.g. make:job SendWelcomeEmail.';
    }

    public function run(array $args): int
    {
        $base = trim($args[0] ?? '', '/\\');
        if ($base === '') {
            fwrite(STDERR, "Usage: make:job <Name>, e.g. make:job SendWelcomeEmail\n");
            return 1;
        }
        $class = str_ends_with($base, 'Job') ? $base : $base . 'Job';

        $path = base_path('app/Jobs/' . $class . '.php');
        if (is_file($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");
            return 1;
        }

        $stub = <<<PHP
        <?php
        declare(strict_types=1);

        namespace App\Jobs;

        use App\Core\Job;

        final class {$class} implements Job
        {
            public function handle(array \$payload): void
            {
            }
        }

        PHP;

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $stub);
        fwrite(STDOUT, "Created {$path}\n");
        return 0;
    }
}
