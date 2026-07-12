<?php
declare(strict_types=1);

return [
    // 'primary' writes to the primary driver only (default). 'all' fans out to
    // every configured backend. 'sync' writes to the primary immediately and
    // queues a replication job (storage/jobs/) for the rest.
    'mode' => env('STORAGE_MODE', 'primary'),

    // Which configured backend is authoritative for reads and single-target writes.
    'primary' => env('STORAGE_PRIMARY', 'local'),

    // Backends to boot, e.g. "local,s3" or "local,webdav1,webdav2".
    'backends' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORAGE_BACKENDS', 'local'))))),

    // Read-fallback order (driver names) tried after the primary fails on get()/exists().
    'fallback' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORAGE_FALLBACK', ''))))),

    'upload_dir' => env('STORAGE_UPLOAD_DIR', base_path('storage/uploads')),
    'sync_dir'   => env('STORAGE_SYNC_DIR', base_path('storage/jobs')),
    'chunk_size' => (int) env('STORAGE_CHUNK_SIZE', 2 * 1024 * 1024),

    's3' => [
        'key'      => env('S3_KEY', ''),
        'secret'   => env('S3_SECRET', ''),
        'region'   => env('S3_REGION', 'us-east-1'),
        'bucket'   => env('S3_BUCKET', ''),
        'endpoint' => env('S3_ENDPOINT', ''),
        'prefix'   => env('S3_PREFIX', ''),
    ],

    'sftp' => [
        'host'         => env('SFTP_HOST', ''),
        'port'         => (int) env('SFTP_PORT', 22),
        'user'         => env('SFTP_USER', ''),
        'password'     => env('SFTP_PASSWORD', ''),
        'privkey_path' => env('SFTP_PRIVKEY_PATH', ''),
        'privkey_pass' => env('SFTP_PRIVKEY_PASS', ''),
        'root'         => env('SFTP_ROOT', '/upload'),
    ],

    'webdav' => [
        'url'      => env('WEBDAV_URL', ''),
        'user'     => env('WEBDAV_USER', ''),
        'password' => env('WEBDAV_PASSWORD', ''),
        'prefix'   => env('WEBDAV_PREFIX', ''),
        'label'    => env('WEBDAV_LABEL', ''),
    ],

    'ftp' => [
        'host'     => env('FTP_HOST', ''),
        'port'     => (int) env('FTP_PORT', 21),
        'user'     => env('FTP_USER', ''),
        'password' => env('FTP_PASSWORD', ''),
        'root'     => env('FTP_ROOT', '/'),
        'ssl'      => (bool) env('FTP_SSL', false),
        'passive'  => (bool) env('FTP_PASSIVE', true),
    ],
];
