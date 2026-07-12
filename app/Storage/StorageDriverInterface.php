<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * StorageDriverInterface
 *
 * Every storage backend (local, S3, SFTP, WebDAV, FTP) implements this.
 * All paths are relative (e.g. "2025/04/01/abc123.pdf").
 */
interface StorageDriverInterface
{
    /**
     * Write a local tmp file to the remote path. Returns true on success.
     * $uuid is a caller-supplied identifier some drivers use to name the
     * remote asset uniquely, independent of the original filename.
     */
    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool;

    /** Read remote file and write to a local tmp file. Returns the tmp path. */
    public function get(string $remotePath): string;

    /** Delete a remote file. */
    public function delete(string $remotePath): bool;

    /** Check if a remote file exists. */
    public function exists(string $remotePath): bool;

    /** Return the driver's short name (local, s3, sftp, webdav, ftp). */
    public function name(): string;
}
