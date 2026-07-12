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

A ready-to-use middleware set (`Auth`, `RateLimit`, `ThrottleAuth`,
`VerifyCsrf`, `RequireCap`, `ApiKey`, `VersionGate`) and a sample surface —
versioned `/api/v1` JSON endpoints, a web front end, a Telegram bot webhook
and Mini App auth flow, and an admin console — show how the pieces fit
together across the `Admin/ Api/ Bot/ Web/` controller groups. JS and PHP
SDK clients (`sdk/o9-sdk.js`, `sdk/O9SDK.php`) speak the framework's
`{ok, data, error, meta}` response envelope out of the box.

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
`setup/scripts/sync-framework.php` and own everything else. See
`setup/MIGRATION.md` for the full procedure to bring an existing project onto
O9 and to pull later framework updates into one that's already migrated.

## Sample surface

`routes/api.php`, `routes/web.php`, and `routes/bot.php` wire up a working
example of every controller group:

- `Api\HealthController`, `PushController`, `SyncController` — versioned
  `/api/v1` JSON endpoints behind `VersionGate`, `Auth`, and `RateLimit`.
- `Web\PageController` — server-rendered pages using `View`/`Seo`/`Lang`.
- `Bot\WebhookController`, `WebAppController`, `AdminBotController` —
  Telegram webhook verification, Mini App `initData` auth, and an
  admin-only command dispatcher.
- `Admin\AdminApiController`, `AdminWebController`, `MediaController`,
  `MonitorController`, `CronController` — an authenticated admin console,
  driver-backed media serving, Prometheus metrics, and a secret-gated
  cron-run endpoint.

Supporting `app/Models/{User,Media}Model.php` and
`app/Services/{Settings,Notification,Cron}Service.php` show the intended
model/service shape; `setup/database/migrations/007-009_*.sql` provide their
schema.

## Development

```bash
composer test   # phpunit
composer stan   # phpstan level 8
```
