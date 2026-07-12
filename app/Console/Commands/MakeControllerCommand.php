<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;

/**
 * Scaffold a controller under one of the framework's four surfaces.
 *   php setup/bin/console make:controller Api/Ping
 *   php setup/bin/console make:controller Web/Page
 * The path segment before the last '/' selects the surface (Admin/Api/Bot/Web);
 * "Controller" is appended to the class name if not already present.
 */
final class MakeControllerCommand implements Command
{
    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Scaffold a controller, e.g. make:controller Api/Ping.';
    }

    public function run(array $args): int
    {
        $arg = $args[0] ?? '';
        if ($arg === '') {
            fwrite(STDERR, "Usage: make:controller <Surface>/<Name>, e.g. make:controller Api/Ping\n");
            return 1;
        }

        $parts = explode('/', str_replace('\\', '/', $arg));
        $base  = array_pop($parts);
        $surface = $parts !== [] ? implode('/', $parts) : 'Web';
        $class = str_ends_with($base, 'Controller') ? $base : $base . 'Controller';
        $namespace = 'App\\Controllers\\' . str_replace('/', '\\', $surface);

        $path = base_path('app/Controllers/' . $surface . '/' . $class . '.php');
        if (is_file($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");
            return 1;
        }

        $stub = <<<PHP
        <?php
        declare(strict_types=1);

        namespace {$namespace};

        use App\Core\BaseController;
        use App\Core\Request;
        use App\Core\Response;

        final class {$class} extends BaseController
        {
            public function index(Request \$request): never
            {
                Response::ok(['message' => 'Hello from {$class}']);
            }
        }

        PHP;

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $stub);
        fwrite(STDOUT, "Created {$path}\n");
        return 0;
    }
}
