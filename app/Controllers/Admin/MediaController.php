<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\HttpException;
use App\Core\MediaFilenameHelper;
use App\Core\Request;
use App\Core\Response;
use App\Core\StorageManager;

/** Serves files through the configured StorageManager — register behind ['Auth:admin']. */
final class MediaController extends BaseController
{
    /** @param array<string, string> $params */
    public function show(Request $request, array $params): never
    {
        $path = (string) ($params['path'] ?? '');
        // Reject traversal before it ever reaches a driver.
        if ($path === '' || str_contains($path, '..')) {
            throw HttpException::notFound('File not found.');
        }

        $storage = StorageManager::instance();
        if (!$storage->exists($path)) {
            throw HttpException::notFound('File not found.');
        }

        $absPath = $storage->get($path);
        $name = basename($path);
        Response::fileFromPath($absPath, $name, MediaFilenameHelper::guessMime($name), (int) (@filesize($absPath) ?: 0));
    }
}
