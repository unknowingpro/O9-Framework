<?php
declare(strict_types=1);

/**
 * Syncs the framework-owned paths listed in setup/framework-manifest.php
 * from this repo (the canonical O9 tree) into a target project.
 *
 * Usage:
 *   php setup/scripts/sync-framework.php <target-project-root> [--dry-run] [--diff]
 *
 * --dry-run  report what would change without writing anything
 * --diff     print a unified diff for every file that would be updated
 *
 * This file is itself framework-owned (see the manifest) — projects run
 * their own synced copy on later releases, not phpframe's directly.
 */

/**
 * @return array{dirs: list<string>, files: list<string>}
 */
function o9_sync_load_manifest(string $sourceRoot): array
{
    $path = $sourceRoot . '/setup/framework-manifest.php';
    if (!is_file($path)) {
        throw new \RuntimeException("Manifest not found: {$path}");
    }
    /** @var mixed $manifest */
    $manifest = require $path;
    if (!is_array($manifest) || !isset($manifest['dirs'], $manifest['files'])) {
        throw new \RuntimeException("Manifest at {$path} is malformed (expected ['dirs' => [...], 'files' => [...]]).");
    }

    return [
        'dirs' => array_values($manifest['dirs']),
        'files' => array_values($manifest['files']),
    ];
}

/**
 * Flattens the manifest into a sorted, deduplicated list of relative file
 * paths (forward-slash, relative to the repo root).
 *
 * @return list<string>
 */
function o9_sync_expand(string $sourceRoot): array
{
    $manifest = o9_sync_load_manifest($sourceRoot);
    $paths = $manifest['files'];

    foreach ($manifest['dirs'] as $dir) {
        $absDir = $sourceRoot . '/' . $dir;
        if (!is_dir($absDir)) {
            throw new \RuntimeException("Manifest directory not found: {$absDir}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($sourceRoot) + 1);
            $paths[] = $relative;
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

/**
 * @return list<array{path: string, status: 'create'|'update'|'unchanged'}>
 */
function o9_sync_plan(string $sourceRoot, string $targetRoot): array
{
    $plan = [];
    foreach (o9_sync_expand($sourceRoot) as $relative) {
        $targetPath = $targetRoot . '/' . $relative;
        if (!is_file($targetPath)) {
            $status = 'create';
        } elseif (hash_file('sha256', $sourceRoot . '/' . $relative) !== hash_file('sha256', $targetPath)) {
            $status = 'update';
        } else {
            $status = 'unchanged';
        }
        $plan[] = ['path' => $relative, 'status' => $status];
    }

    return $plan;
}

/**
 * Copies every 'create'/'update' entry in the plan from source to target,
 * creating target directories as needed.
 *
 * @param list<array{path: string, status: 'create'|'update'|'unchanged'}> $plan
 * @return array{created: int, updated: int, unchanged: int}
 */
function o9_sync_apply(array $plan, string $sourceRoot, string $targetRoot): array
{
    $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($plan as $entry) {
        if ($entry['status'] === 'unchanged') {
            $counts['unchanged']++;
            continue;
        }
        $targetPath = $targetRoot . '/' . $entry['path'];
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Could not create directory: {$targetDir}");
        }
        if (!copy($sourceRoot . '/' . $entry['path'], $targetPath)) {
            throw new \RuntimeException("Failed to copy {$entry['path']} to {$targetPath}");
        }
        $counts[$entry['status'] === 'create' ? 'created' : 'updated']++;
    }

    return $counts;
}

function o9_sync_diff(string $sourceRoot, string $targetRoot, string $relative): string
{
    $from = escapeshellarg($targetRoot . '/' . $relative);
    $to = escapeshellarg($sourceRoot . '/' . $relative);
    $output = shell_exec("diff -u {$from} {$to}");

    return is_string($output) ? $output : '';
}

/**
 * @param list<string> $argv
 */
function o9_sync_main(array $argv): int
{
    $args = array_slice($argv, 1);
    $flags = array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, '--')));
    $positional = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));

    if ($positional === []) {
        fwrite(STDERR, "Usage: php setup/scripts/sync-framework.php <target-project-root> [--dry-run] [--diff]\n");
        return 1;
    }

    $sourceRoot = dirname(__DIR__, 2);
    $targetRoot = rtrim($positional[0], '/');
    if (!is_dir($targetRoot)) {
        fwrite(STDERR, "Target project root does not exist: {$targetRoot}\n");
        return 1;
    }
    if (realpath($targetRoot) === realpath($sourceRoot)) {
        fwrite(STDERR, "Refusing to sync phpframe onto itself.\n");
        return 1;
    }

    $dryRun = in_array('--dry-run', $flags, true);
    $showDiff = in_array('--diff', $flags, true);

    $plan = o9_sync_plan($sourceRoot, $targetRoot);
    $changed = array_values(array_filter($plan, static fn (array $e): bool => $e['status'] !== 'unchanged'));

    foreach ($changed as $entry) {
        fwrite(STDOUT, sprintf("%-8s %s\n", strtoupper($entry['status']), $entry['path']));
        if ($showDiff && $entry['status'] === 'update') {
            fwrite(STDOUT, o9_sync_diff($sourceRoot, $targetRoot, $entry['path']));
        }
    }

    if ($dryRun) {
        fwrite(STDOUT, sprintf("Dry run: %d to create, %d to update, %d unchanged.\n",
            count(array_filter($changed, static fn (array $e): bool => $e['status'] === 'create')),
            count(array_filter($changed, static fn (array $e): bool => $e['status'] === 'update')),
            count($plan) - count($changed)
        ));
        return 0;
    }

    $counts = o9_sync_apply($plan, $sourceRoot, $targetRoot);
    fwrite(STDOUT, sprintf(
        "Synced into %s: %d created, %d updated, %d unchanged.\n",
        $targetRoot,
        $counts['created'],
        $counts['updated'],
        $counts['unchanged']
    ));

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(o9_sync_main($argv));
}
