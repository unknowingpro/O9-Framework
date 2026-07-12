<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * FileManagerInterface
 *
 * Extended contract for storage drivers that support directory listing,
 * rename/move, copy, and folder creation — for building a file-browser UI.
 *
 * Drivers implementing this are "browsable". It extends StorageDriverInterface,
 * so a browsable driver is always a full storage driver too.
 *
 * Each entry in listDirectory() returns a normalized FileEntry shape:
 *
 *   [
 *     'name'     => 'report.pdf',           // basename
 *     'path'     => 'docs/2025/report.pdf', // full path relative to root
 *     'type'     => 'file',                 // 'file' | 'dir'
 *     'size'     => 1048576,                // bytes (null for dirs)
 *     'modified' => 1711900800,             // Unix timestamp (null if unknown)
 *     'mime'     => 'application/pdf',      // null for dirs
 *     'is_dir'   => false,
 *   ]
 */
interface FileManagerInterface extends StorageDriverInterface
{
    /**
     * List files and subdirectories at the given path.
     *
     * @param string $path Path relative to the driver's root ('' = root)
     * @return list<array{name: string, path: string, type: string, size: int|null, modified: int|null, mime: string|null, is_dir: bool}>
     */
    public function listDirectory(string $path = ''): array;

    /** Create a directory (and any missing parents). */
    public function makeDirectory(string $path): bool;

    /** Move / rename a file or directory within the same driver (paths relative to root). */
    public function move(string $from, string $to): bool;

    /** Copy a file within the same driver (paths relative to root). */
    public function copy(string $from, string $to): bool;

    /** Delete a file or directory (recursive for dirs), path relative to root. */
    public function deleteItem(string $path): bool;

    /** Download a file from the remote to a local tmp file. Same as get() but explicit for the manager. */
    public function download(string $path): string;

    /** Upload a local file to the remote path. */
    public function upload(string $localPath, string $remotePath): bool;

    /**
     * Disk-usage information for this driver.
     *
     * @return array<string, mixed>
     */
    public function quota(): array;
}
