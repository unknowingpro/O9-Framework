# O9 Framework

Zero-dependency PHP framework (PHP 8.2+) extracted from four production
applications. Composer is optional: a bundled PSR-4 autoloader
(`app/Core/Autoloader.php`) covers composer-less deployments.

O9 provides routing, an immutable request wrapper, enveloped JSON responses
with streaming/range support, a PDO query builder, pluggable cache stores
(array/file/Redis), a DB-backed job queue with worker runtime, driver-based
file storage (local/S3/SFTP/WebDAV/FTP) with fallback chains, JWT/TOTP/crypto
security primitives, a DB-driven 21-locale i18n system, mail transports,
a console kernel with scheduling, and Prometheus/OpenAPI observability —
all under the `App\` namespace in the canonical `app/` tree.

## Quick start

```bash
composer install          # optional — runs without it
cp .env.example .env      # set JWT_SECRET (32+ random chars)
php setup/setup.php       # first-run installer (storage dirs, DB schema)
php -S localhost:8000 -t public
```

Web server document root: `public/`. Single entry point: `public/index.php`.

## Layout

The repository follows the canonical tree documented in `docs/` (dev docs are
not tracked in git). Framework-owned paths are listed in
`setup/framework-manifest.php`; applications built on O9 sync those paths via
`setup/scripts/sync-framework.php` and own everything else.

## Development

```bash
composer test   # phpunit
composer stan   # phpstan level 8
```
