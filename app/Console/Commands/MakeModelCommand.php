<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;

/**
 * Scaffold a model.
 *   php setup/bin/console make:model Product
 * "Model" is appended to the class name if not already present; the table
 * name is guessed as the snake_case plural of the base name (edit the
 * generated $table property if that guess is wrong).
 */
final class MakeModelCommand implements Command
{
    public function name(): string
    {
        return 'make:model';
    }

    public function description(): string
    {
        return 'Scaffold a model, e.g. make:model Product.';
    }

    public function run(array $args): int
    {
        $arg = $args[0] ?? '';
        if ($arg === '') {
            fwrite(STDERR, "Usage: make:model <Name>, e.g. make:model Product\n");
            return 1;
        }

        $base  = trim($arg, '/\\');
        $class = str_ends_with($base, 'Model') ? $base : $base . 'Model';
        $bareName = str_ends_with($base, 'Model') ? substr($base, 0, -5) : $base;
        $table = $this->snakePlural($bareName);

        $path = base_path('app/Models/' . $class . '.php');
        if (is_file($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");
            return 1;
        }

        $stub = <<<PHP
        <?php
        declare(strict_types=1);

        namespace App\Models;

        use App\Core\BaseModel;

        final class {$class} extends BaseModel
        {
            protected string \$table = '{$table}';
        }

        PHP;

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $stub);
        fwrite(STDOUT, "Created {$path}\n");
        return 0;
    }

    private function snakePlural(string $name): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }
}
