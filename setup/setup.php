<?php
declare(strict_types=1);

/**
 * First-run installer. Run once from the project root:
 *
 *   php setup/setup.php
 *
 * Idempotent — safe to run again later (e.g. after sync-framework.php pulls
 * in a new migration): it never overwrites a secret or file that's already
 * set, and re-running just applies whatever's newly pending.
 *
 * What it does:
 *   1. Checks the PHP version and the extensions composer.json requires.
 *   2. Creates .env from .env.example if .env doesn't exist yet.
 *   3. Generates JWT_SECRET / APP_KEY if either is still blank.
 *   4. Creates the storage/ runtime subdirectories.
 *   5. Applies pending database migrations (best-effort — a DB that isn't
 *      reachable yet doesn't fail the install; run `migrate` later).
 */

// Every other entry point (public/index.php, setup/bin/console) defines
// this before requiring anything else — helpers.php's base_path() falls
// back to a path relative to wherever it physically loaded from when this
// isn't set, which is only reliably this project's root when Composer's
// autoloader was generated INSIDE it (not a shared/symlinked vendor/).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * @return list<array{name: string, ok: bool, detail: string}>
 */
function o9_setup_check_requirements(): array
{
    $checks = [];

    $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
    $checks[] = ['name' => 'PHP >= 8.2.0', 'ok' => $phpOk, 'detail' => 'running ' . PHP_VERSION];

    foreach (['json', 'pdo', 'curl', 'mbstring'] as $ext) {
        $checks[] = [
            'name' => "ext-{$ext}",
            'ok' => extension_loaded($ext),
            'detail' => extension_loaded($ext) ? 'loaded' : 'missing (required)',
        ];
    }
    foreach (['redis', 'intl'] as $ext) {
        $checks[] = [
            'name' => "ext-{$ext}",
            'ok' => true,
            'detail' => extension_loaded($ext) ? 'loaded' : 'not installed (optional — see composer.json suggest)',
        ];
    }

    return $checks;
}

/**
 * @return array{created: bool, path: string}
 */
function o9_setup_ensure_env_file(string $root): array
{
    $envPath = $root . '/.env';
    if (is_file($envPath)) {
        return ['created' => false, 'path' => $envPath];
    }
    $examplePath = $root . '/.env.example';
    if (!is_file($examplePath)) {
        throw new \RuntimeException(".env.example not found at {$examplePath}");
    }
    if (!copy($examplePath, $envPath)) {
        throw new \RuntimeException("Failed to create {$envPath} from .env.example");
    }

    return ['created' => true, 'path' => $envPath];
}

function o9_setup_random_jwt_secret(): string
{
    return bin2hex(random_bytes(32));
}

function o9_setup_random_app_key(): string
{
    return base64_encode(random_bytes(32));
}

/**
 * True if $rawValue is blank by App\Core\Env's own rules: unquoted empty,
 * quoted empty ('' / ""), or one of Env::coerce()'s blank/null sentinel
 * literals. Mirroring that vocabulary here matters — a value this function
 * misjudges as "already set" is a secret ensure_secret() will never fill in.
 */
function o9_setup_is_blank_env_value(string $rawValue): bool
{
    $value = trim($rawValue);
    if (strlen($value) >= 2
        && ($value[0] === '"' || $value[0] === "'")
        && $value[strlen($value) - 1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    return match (strtolower($value)) {
        '', 'null', '(null)', 'empty', '(empty)' => true,
        default => false,
    };
}

/**
 * Sets KEY= in the .env file at $envPath to $value, but only when the
 * existing value is blank per o9_setup_is_blank_env_value() — never
 * overwrites a secret that's already set. Appends a new KEY=value line if
 * the key isn't present at all.
 */
function o9_setup_ensure_secret(string $envPath, string $key, string $value): bool
{
    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new \RuntimeException("Could not read {$envPath}");
    }

    $found = false;
    foreach ($lines as $i => $line) {
        if (!str_starts_with($line, $key . '=')) {
            continue;
        }
        $found = true;
        if (!o9_setup_is_blank_env_value(substr($line, strlen($key) + 1))) {
            return false; // already set — leave it alone
        }
        $lines[$i] = $key . '=' . $value;
        break;
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }

    if (file_put_contents($envPath, implode("\n", $lines) . "\n") === false) {
        throw new \RuntimeException("Could not write {$envPath}");
    }

    return true;
}

/**
 * @return list<string> the subdirectories actually created (already-existing ones are skipped)
 */
function o9_setup_scaffold_storage_dirs(string $root): array
{
    $dirs = [
        'storage/logs',
        'storage/cache',
        'storage/database',
        'storage/tmp',
        'storage/jobs',
        'storage/uploads',
        'storage/data/state',
    ];

    $created = [];
    foreach ($dirs as $dir) {
        $abs = $root . '/' . $dir;
        if (is_dir($abs)) {
            continue;
        }
        if (!mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new \RuntimeException("Could not create {$abs}");
        }
        $created[] = $dir;
    }

    return $created;
}

/**
 * Applies pending migrations against whatever DB config/database.php
 * currently resolves to. Best-effort: any failure — the DB isn't reachable
 * yet, or a migration file itself is broken — is reported, not thrown, so
 * first boot can fix it afterwards and run `php setup/bin/console migrate`.
 *
 * @return array{applied: list<string>, error: ?string}
 */
function o9_setup_run_migrations(string $root): array
{
    if (is_file($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
    } else {
        require_once $root . '/app/Core/Autoloader.php';
        \App\Core\Autoloader::register();
        require_once $root . '/app/Core/helpers.php';
    }
    require_once $root . '/config/env.php';

    try {
        // applyAll() already no-ops safely when nothing is pending, so no
        // separate pending()-then-applyAll() pre-check is needed here.
        return ['applied' => (new \App\Services\MigrationsService())->applyAll(), 'error' => null];
    } catch (\Throwable $e) {
        return ['applied' => [], 'error' => $e->getMessage()];
    }
}

function o9_setup_main(array $argv): int
{
    $root = dirname(__DIR__);

    fwrite(STDOUT, "O9 setup — {$root}\n\n");

    fwrite(STDOUT, "Requirements:\n");
    $hardFailure = false;
    foreach (o9_setup_check_requirements() as $check) {
        fwrite(STDOUT, sprintf("  [%s] %-16s %s\n", $check['ok'] ? 'ok' : 'FAIL', $check['name'], $check['detail']));
        if (!$check['ok']) {
            $hardFailure = true;
        }
    }
    if ($hardFailure) {
        fwrite(STDERR, "\nRequirements not met — install the missing extension(s)/PHP version and re-run.\n");
        return 1;
    }

    $env = o9_setup_ensure_env_file($root);
    fwrite(STDOUT, "\n.env: " . ($env['created'] ? 'created from .env.example' : 'already exists (left untouched)') . "\n");

    $jwtGenerated = o9_setup_ensure_secret($env['path'], 'JWT_SECRET', o9_setup_random_jwt_secret());
    $keyGenerated = o9_setup_ensure_secret($env['path'], 'APP_KEY', o9_setup_random_app_key());
    fwrite(STDOUT, 'JWT_SECRET: ' . ($jwtGenerated ? 'generated' : 'already set') . "\n");
    fwrite(STDOUT, 'APP_KEY: ' . ($keyGenerated ? 'generated' : 'already set') . "\n");

    $createdDirs = o9_setup_scaffold_storage_dirs($root);
    fwrite(STDOUT, "\nstorage/: " . ($createdDirs === [] ? 'already scaffolded' : 'created ' . implode(', ', $createdDirs)) . "\n");

    fwrite(STDOUT, "\nMigrations:\n");
    $migrations = o9_setup_run_migrations($root);
    if ($migrations['error'] !== null) {
        fwrite(STDOUT, "  could not apply: {$migrations['error']}\n");
        fwrite(STDOUT, "  fix the issue above (DB connectivity/credentials in .env, or the migration file it names),\n");
        fwrite(STDOUT, "  then run: php setup/bin/console migrate\n");
    } elseif ($migrations['applied'] === []) {
        fwrite(STDOUT, "  nothing to apply.\n");
    } else {
        fwrite(STDOUT, '  applied ' . count($migrations['applied']) . ': ' . implode(', ', $migrations['applied']) . "\n");
    }

    fwrite(STDOUT, "\nDone. Next steps:\n");
    fwrite(STDOUT, "  - point your web server at public/ (see setup/webserver/)\n");
    fwrite(STDOUT, "  - add setup/scripts/cron.sh to crontab for scheduled tasks\n");
    fwrite(STDOUT, "  - php setup/bin/console  (list available commands)\n");

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(o9_setup_main($argv));
}
