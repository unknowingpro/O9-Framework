<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;

/**
 * Scaffold a middleware.
 *   php setup/bin/console make:middleware ApiKey
 */
final class MakeMiddlewareCommand implements Command
{
    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Scaffold a middleware, e.g. make:middleware ApiKey.';
    }

    public function run(array $args): int
    {
        $class = trim($args[0] ?? '', '/\\');
        if ($class === '') {
            fwrite(STDERR, "Usage: make:middleware <Name>, e.g. make:middleware ApiKey\n");
            return 1;
        }

        $path = base_path('app/Middleware/' . $class . '.php');
        if (is_file($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");
            return 1;
        }

        $stub = <<<PHP
        <?php
        declare(strict_types=1);

        namespace App\Middleware;

        use App\Core\Middleware;
        use App\Core\Request;

        final class {$class} implements Middleware
        {
            public function handle(Request \$request, ?string \$arg = null): void
            {
                // Short-circuit with `throw new \App\Core\HttpResponse(...)` to reject the request.
            }
        }

        PHP;

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $stub);
        fwrite(STDOUT, "Created {$path}\n");
        return 0;
    }
}
