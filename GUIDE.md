# O9 — Developer Guide

Everything you need to build an application on O9. Read `README.md` first for
what the framework is; this document is how you use it.

- [1. Starting a project](#1-starting-a-project)
- [2. How a request flows](#2-how-a-request-flows)
- [3. Routing](#3-routing)
- [4. Controllers, Request, Response](#4-controllers-request-response)
- [5. Middleware](#5-middleware)
- [6. Configuration and environment](#6-configuration-and-environment)
- [7. Database, models, resources](#7-database-models-resources)
- [8. Validation](#8-validation)
- [9. Auth and security](#9-auth-and-security)
- [10. Cache](#10-cache)
- [11. Queue, jobs, events](#11-queue-jobs-events)
- [12. File storage](#12-file-storage)
- [13. Views](#13-views)
- [14. i18n](#14-i18n)
- [15. Console and scheduling](#15-console-and-scheduling)
- [16. Operational gates](#16-operational-gates)
- [17. Utilities](#17-utilities)
- [18. Observability](#18-observability)
- [19. Ai](#19-ai)
- [20. Notifications](#20-notifications)
- [21. Testing](#21-testing)
- [22. Staying in sync with the framework](#22-staying-in-sync-with-the-framework)

---

## 1. Starting a project

```bash
git clone <phpframe> myapp && cd myapp
composer install            # optional — the bundled autoloader covers composer-less installs
cp .env.example .env        # set APP_KEY and JWT_SECRET (32+ random chars each)
php setup/setup.php         # creates storage dirs, runs migrations, seeds locales
php -S localhost:8000 -t public
```

Point the web server's document root at `public/`. Everything enters through
`public/index.php`; nothing else is web-reachable.

`setup/webserver/` has ready nginx and Apache configs.

**What you own vs what the framework owns.** `setup/framework-manifest.php` is
the contract: every path it lists (`app/Core`, `app/Middleware`, `app/Storage`,
`app/Console`, `app/Mail`, `app/Jobs`, `app/Identity`, `app/Payments`, …) is
framework code. Never hand-edit those in your project — fix it upstream in
phpframe and re-sync (§20). Everything else — `app/Controllers`, `app/Models`,
`app/Services`, `app/Resources`, `app/Views`, `config/`, `routes/`,
`app/Lang/*.php` — is yours.

## 2. How a request flows

```
public/index.php
  → App::run()
      env → config → error handlers → logger → container bindings → event listeners
      → session (skipped for /api/* — those are stateless)
      → CORS (an OPTIONS preflight ends here)
      → Maintenance gate  (503)
      → GeoBlock gate     (451)
      → ApiContext (correlation id + access log, /api/* only)
      → route file loaded by path prefix (api.php | bot.php | web.php)
      → Router::dispatch()
          → middleware pipeline
              → controller
      → response emitted
```

Any layer can end the request immediately by **throwing** — `Response::ok()`,
`Response::error()`, `View::redirect()`, `Maintenance::serve()` all throw an
`HttpResponse` that `App::run()` catches and emits. That is why they are typed
`never` and why you never `return` them.

## 3. Routing

`routes/api.php`, `routes/web.php`, `routes/bot.php` are loaded conditionally by
path prefix, so a bot webhook never pays to parse your web routes. Each file gets
`$router`.

```php
/** @var \App\Core\Router $router */

$router->get('/health', [HealthController::class, 'index']);
$router->post('/items', [ItemController::class, 'store'], [Auth::class]);
$router->get('/items/{id}', [ItemController::class, 'show']);

// Groups apply a prefix + middleware to everything inside.
$router->group('/api/v1', [VersionGate::class], function ($router): void {
    $router->post('/auth/login', [AuthController::class, 'login'], [ThrottleAuth::class]);

    $router->group('', [Auth::class], function ($router): void {
        $router->get('/me', [MeController::class, 'show']);
    });
});
```

Verbs: `get post put patch delete head options`. A handler is
`[Class::class, 'method']`, `'Class@method'`, or a closure. `{param}` segments
arrive via `$request->param('id')`.

## 4. Controllers, Request, Response

```php
final class ItemController extends BaseController
{
    public function show(Request $request): never
    {
        $id = (int) $request->param('id');
        $item = (new ItemModel())->find($id);

        if (!$item) {
            Response::notFound('No such item.');
        }

        Response::ok(ItemResource::make($item)->toArray());
    }
}
```

**Request** — `param() query() bodyParam() input() all() header() method() path()
ip() bearerToken() file() actor() wantsJson() cookie()`. Immutable; read-only.

**Response** — every method throws:

| Call | Result |
|---|---|
| `Response::ok($data, $meta)` | `200 {ok:true, data, error:null}` |
| `Response::created($data)` | `201` |
| `Response::paginated($items, $page, $perPage, $total)` | `200` + pagination `meta` |
| `Response::okCached($data)` | `200` + ETag, `304` when it matches |
| `Response::error($code, $msg, $status)` | `{ok:false, data:null, error:{code,message}}` |
| `Response::notFound() / unauthorized() / forbidden()` | `404 / 401 / 403` |
| `Response::html($html)` | raw HTML |
| `Response::download() / streamDownload()` | file body / streamed |

Use the canonical codes in `ApiError` (`validation_failed`, `rate_limited`,
`maintenance`, …) so clients can branch on `error.code` instead of parsing prose.
`ApiError::fail(ApiError::CONFLICT)` emits the code with its correct status.

## 5. Middleware

```php
final class RequireTeam implements Middleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        if (!Auth::check()) {
            Response::unauthorized();   // throws — pipeline stops here
        }
        // return normally to pass through
    }
}
```

Reference it three ways: `RequireTeam::class`, an instance
(`new RateLimit(240, 60, 'api')`), or `'RateLimit:auth'` — the string after the
colon arrives as `$arg`.

Shipped: `Auth` (JWT + session), `ApiKey`, `RateLimit`, `ThrottleAuth`,
`VerifyCsrf`, `RequireCap` (capability gate), `VersionGate` (force-update).

## 6. Configuration and environment

`config/*.php` returns arrays; read with dot-notation. Never read `$_ENV`
directly outside a config file.

```php
config('app.name');
config('storage.upload_dir');
config('currencies.supported');
env('JWT_SECRET');          // config files only
```

Secrets live in `.env` (gitignored). `.env.example` documents every key.
`APP_KEY` and `JWT_SECRET` are mandatory — the kernel refuses to boot in
production without them.

## 7. Database, models, resources

`Db` is the terse static facade; `QueryBuilder` is fluent; `BaseModel` gives you
both. SQLite and MySQL are supported by the same code.

```php
Db::first('SELECT * FROM users WHERE email = ?', [$email]);
Db::all('SELECT * FROM items WHERE type = ?', [$type]);
$id = Db::insert('INSERT INTO items (title) VALUES (?)', [$title]);
Db::execute('UPDATE items SET seen = 1 WHERE id = ?', [$id]);
Db::upsert('items', ['imdb_id'], ['title' => 'excluded.title'], $row);
```

```php
final class ItemModel extends BaseModel
{
    protected string $table = 'items';

    public function published(int $limit = 20): array
    {
        return $this->table()
            ->where('status', '=', 'published')
            ->whereNotNull('published_at')
            ->orderBy('published_at', 'DESC')
            ->limit($limit)
            ->get();
    }
}
```

`BaseModel` provides `find() create() updateById() deleteById() softDeleteById()`
and `table()` for the builder. Every value is bound — never interpolate into SQL.

**Cache-aware models** extend `CachedModel` instead of `BaseModel`. `find()` is
wrapped in `CacheManager::remember()` and auto-evicted on writes, so a second
call to the same ID hits cache instead of the database:

```php
final class ProductModel extends CachedModel
{
    protected string $table = 'products';
    protected ?int $cacheTtl = 300;              // default: config('cache.default_ttl', 3600)
}

$model = new ProductModel();
$p = $model->find(1);        // DB query — cached
$p = $model->find(1);        // from cache

$model->updateById(1, ['price' => 9.99]);         // evicts that ID
$model->deleteById(1);                             // evicts ID + all cache
$model->cachedAll();                               // all rows, cached
$model->forgetFind(1);                             // manual eviction
$model->forgetAll();                               // evict the all-cache
```

**Resources are how a row becomes a response.** A resource is an allow-list, so a
column you add to the table later stays invisible to the API until you opt it in.
This is the opposite of returning the row and `unset()`-ing the secrets, which
leaks every new sensitive column by default.

```php
final class ItemResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id'    => (int) $this->data['id'],
            'title' => (string) $this->data['title'],
        ];
    }
}

ItemResource::make($row)->toArray();
ItemResource::collection($rows);
```

Migrations are raw SQL in `setup/database/migrations/`, one file per step, with a
`.mysql.sql` / `.sqlite.sql` pair where the dialects differ. Run them with
`php setup/bin/console migrate`.

## 8. Validation

From a controller, `validate()` returns the validated subset or emits a `422`
envelope with per-field errors:

```php
$data = $this->validate($request->all(), [
    'email'    => 'required|email|max:191',
    'password' => 'required|min:12',
    'role'     => 'nullable|in:member,coach',
    'owner_id' => 'required|int|exists:users,id',
]);
```

Rules: `required nullable email url int numeric boolean array min:N max:N in:a,b
regex:/…/ date confirmed`, plus the DB rules `unique:tbl,col[,ignoreId]` and
`exists:tbl,col`. The cast rules (`int`, `numeric`, `boolean`) coerce the value
they return.

Outside a controller, `Validator::check()` is the pure form — it never throws and
never exits, it returns `['valid' => bool, 'data' => [...], 'errors' => [...]]`,
so you can validate in a service or a console command and decide what to do.

Add a project rule with
`Validator::extend('iban', fn (string $field, mixed $value, ?string $param) => ...)`
— return an error message, or `null` when it passes.

**File upload validation.** `UploadValidator` validates a `$_FILES` entry against
MIME type (sniffed via finfo, not the client header) and size:

```php
$result = UploadValidator::validate($_FILES['avatar'], [
    'mimes'    => ['jpg', 'jpeg', 'png', 'webp'],
    'max_size' => 2048,              // KB
    'required' => true,
]);

if (!$result['valid']) {
    // $result['errors'] — list of error messages
}
// $result['data'] — ['tmp_name', 'name', 'size', 'mime', 'ext'] on success

UploadValidator::passes($_FILES['avatar'], ['mimes' => ['png']]);        // bool
UploadValidator::validData($_FILES['avatar'], ['mimes' => ['png']]);     // array|null
```

## 9. Auth and security

`Auth` resolves the current actor from a Bearer JWT or a resumed web session:
`Auth::check() Auth::id() Auth::user() Auth::hasRole('admin') Auth::login($id)
Auth::logout()`. Tell it how to load a user once, in `app/bootstrap.php`:

```php
Auth::resolveUserUsing(fn (int $id) => (new UserModel())->find($id));
```

`Core/Security/` holds the primitives: `Jwt` (sign/verify), `Hash` (password
hashing), `Crypto` (authenticated encryption with `APP_KEY`), `Totp` (2FA),
`RefreshTokenService` (rotating refresh tokens, hashed at rest).

Rules that the framework enforces and you should not work around: passwords go
through `Hash`, never `md5`/`sha1`. Secrets come from `.env`, never literals.
State-changing web forms carry a CSRF token (`Session::csrf()`, `VerifyCsrf`).
Output is escaped with `e()` unless you have deliberately sanitized it.

## 10. Cache

```php
CacheManager::remember('items:top', 300, fn () => $model->published());
CacheManager::get($k, $default);
CacheManager::set($k, $v, $ttl);
CacheManager::forget($k);
CacheManager::increment($k);
CacheManager::flush();
```

Stores: array (tests), file (default), Redis (`CACHE_DRIVER=redis`). Same API for
all three; Redis also backs sessions and gives rate limiting an atomic counter.

## 11. Queue, jobs, events

```php
Queue::push(SendEmailJob::class, ['to' => $email], delaySeconds: 0);
```

```php
final class SendReportJob implements Job     // Core\Job is an interface
{
    public function handle(array $payload): void { /* ... */ }
}
```

DB-backed, with reserve/backoff/bury. Run a worker with
`php setup/bin/console queue:work`. Failures retry with exponential backoff and
are buried after the limit rather than spinning forever.

Events are the decoupling seam:

```php
Events::listen('user.registered', fn ($u) => Queue::push(SendWelcomeJob::class, $u));
Events::dispatch('user.registered', $user);       // synchronous
Events::dispatchAsync('user.registered', $user);  // via the queue
```

Register listeners in `app/Core/EventListeners.php`.

## 12. File storage

One API, five drivers (`local`, `s3`, `sftp`, `webdav`, `ftp`), configured in
`config/storage.php` with a PRIMARY driver and an optional FALLBACK chain — if
the primary write fails, the next driver takes it.

```php
$storage = StorageManager::instance();
$storage->put($tmpPath, 'avatars/42.jpg');
$storage->get('avatars/42.jpg');
$storage->exists($path);
$storage->delete($path);
```

S3 is pure cURL SigV4 — no AWS SDK, so it also speaks MinIO/R2/Wasabi. Uploads
are written outside the webroot and served through a controller, never linked
directly.

## 13. Views

```php
View::render('pages/home', ['items' => $items], 'layouts/main');   // throws — ends the request
View::capture('partials/row', ['item' => $item]);                  // returns a string
View::component('callout', ['tone' => 'warn'], 'Careful.');
```

Plus sections (`startSection/endSection/yieldSection`) and stacks
(`push/stack`) for per-page CSS/JS. **Escape everything**: `<?= e($user['name']) ?>`.

## 14. i18n

21 locales ship in `app/Lang/`. Translate with `__()`:

```php
__('welcome.title');
__('cart.items', ['count' => 3]);
__n('file', 'files', $n);          // CLDR plurals
```

Locale resolution, first match wins: `?lang=` → the signed-in user's saved
locale → `lang` cookie → `Accept-Language` → config default. English is the
fallback; a missing key falls back to English, then to the key itself, so a
gap degrades readably instead of printing blank.

`LanguageService` is a DB-driven registry of *active* languages.
**Adding a language is one INSERT + one `app/Lang/xx.php` file + `flushCache()`
— zero code changes.** `Lang::direction()` gives you `rtl`/`ltr`.

## 15. Console and scheduling

```bash
php setup/bin/console migrate
php setup/bin/console queue:work
php setup/bin/console schedule:run
php setup/bin/console cache:clear
php setup/bin/console db:seed
php setup/bin/console make:controller ItemController
php setup/bin/console make:model ItemModel
php setup/bin/console make:job SendReportJob
php setup/bin/console make:middleware RequireTeam
```

**Seeders** populate the database with reference or test data. Write a seeder in
`setup/database/seeders/` that extends `Seeder`:

```php
final class RegionSeeder extends Seeder
{
    public function run(array $args = []): void
    {
        if ($this->isFresh($args)) {
            $this->truncate('regions');
        }
        Db::insert("INSERT INTO regions (code, name) VALUES (?, ?)", ['eu', 'Europe']);
    }
}
```

```bash
php setup/bin/console db:seed                  # run all seeders
php setup/bin/console db:seed --class=Region   # run one
php setup/bin/console db:seed --fresh          # truncate first
```

Write your own command by extending `Console\Command` and registering it in
`Console\Kernel`. Schedule work in `Console\Schedule`, then point cron at
`schedule:run` once a minute (see `setup/scripts/cron.sh`).

**Guard anything periodic with `Lock`** so a slow run cannot pile up on top of
itself:

```php
if (!Lock::acquire('reports:nightly')) {
    return self::SUCCESS;   // still running from the last tick — skip
}
```

## 16. Operational gates

**Maintenance mode** — 503 for everyone except admins, the auth routes, and
assets. Two independent switches, either one turns it on:

```bash
touch storage/maintenance.flag                       # message = first line of the file
echo "Back at 17:00 UTC" > storage/maintenance.flag
```

…or the `maintenance_on` setting from the admin panel. The file is not
redundant: the setting lives in the database, so when the database is what's
broken — precisely when you need to go down — it cannot be read or written. The
file needs nothing but a filesystem.

**Geo-blocking** — 451 by country, from the CDN edge header (`CF-IPCountry` and
generic fallbacks). Enable with the `security.geo_blocking` setting and list
countries in `security.geo_blocked_countries`. It **fails open** when the country
is unknown, so a missing header can never lock the site out, and admins/auth/
assets are always exempt so you cannot geo-block yourself away from the switch
that turns it off.

> Only enable geo-blocking behind a CDN that *overwrites* the country header. If
> the app is directly reachable, a caller can simply send their own.

## 17. Utilities

| Class | Use |
|---|---|
| `Money` | Integer minor-unit math. **Money is never a float.** `Money::toMinor('12.34', 'USD') === 1234`. The `config/currencies.php` registry knows each currency's exponent, so nothing hard-codes `/100` — which mis-scales IRR by 100x and USDT by 10,000x. An unconfigured currency throws instead of guessing. |
| `Units` | metric/imperial conversion. Storage is always SI (kg, cm); the preference only changes display and input. |
| `Slug` | `Slug::make()`, `shortId()`, `shortcode()`, and `unique($base, $exists)` to avoid collisions. Non-Latin input slugs to `''` — fall back to `shortcode()`. |
| `Uuid` | `Uuid::v4()`, `Uuid::isValid()`. The `uuid()` helper delegates here. |
| `Url` | `Url::safe($raw)` — only `http(s)` with a host survives; blocks `javascript:`/`data:`/`file:`. `Url::mediaDiskPath($url)` resolves a media URL to disk **only if it stays inside the upload root** — use it before any `unlink()`/`rename()` driven by a DB column. |
| `Lock` | flock single-instance guard (§15). |
| `Csv`, `Paginator`, `Hashid`, `Seo`, `HttpClient`, `CircuitBreaker` | CSV I/O, page/cursor pagination, opaque public ids, meta/JSON-LD tags, cURL with retries, and a breaker for flaky upstreams. |
| `Image` | GD-based resize, crop, centre-crop thumbnail, re-compress/strip EXIF — zero dependencies. `Image::resize($src, 800, 600, $out)`, `Image::crop()`, `Image::thumbnail()`, `Image::optimize()`, `Image::info()`. All methods accept file paths; `$output` defaults to overwriting the source. |

## 18. Observability

`Logger` writes JSON-lines to `storage/logs/app-YYYY-MM-DD.log`. Every `/api/*`
request gets a correlation id (`X-Request-Id`, echoed back) that appears on every
line it produces — that is how you reconstruct one request out of a busy log.

`/metrics` renders Prometheus text format (`Metrics::collect()`); the admin
`MonitorController` surfaces the same numbers. `OpenApiGenerator` introspects the
router into an OpenAPI 3 document, so the API docs cannot drift from the routes.

## 19. Ai

```php
$ai = AiProviderFactory::make();                          // reads config('ai.provider')
$response = $ai->chat('gpt-4o', [
    ['role' => 'user', 'content' => 'Explain OOP in one sentence.'],
]);
echo $response->content;                                  // string
echo $response->inputTokens;                              // ?int
```

The `AiProvider` port has two methods. `chat()` returns an `AiResponse` value
object with `content`, `model`, token counts, and `finishReason`. `stream()`
returns a `Generator` yielding `AiStreamChunk` objects as each delta arrives:

```php
foreach ($ai->stream('gpt-4o', $messages) as $chunk) {
    echo $chunk->delta;
    if ($chunk->finishReason !== null) {
        // stream finished — $chunk->outputTokens may be set
    }
}
```

**Drivers.** Only `OpenAiProvider` ships in core. It speaks the OpenAI-compatible
REST API (`/v1/chat/completions`) and works with OpenAI, Anthropic (in proxy
mode), Ollama, vLLM, Groq, Together, and every other provider using the same wire
format. Configure the base URL per provider in `config/ai.php`:

```php
'providers' => [
    'openai' => [
        'api_key'  => env('OPENAI_API_KEY', ''),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],
],
```

Register a custom provider (e.g. a native Anthropic client) via the factory:

```php
AiProviderFactory::extend('anthropic', fn () => new AnthropicProvider(
    config('ai.providers.anthropic'),
));
```

`AiProviderFactory::make('anthropic')` then returns your provider.

Both `chat()` and `stream()` accept the standard OpenAI options in `$options`:
`temperature`, `max_tokens`, `top_p`, `stop`, `frequency_penalty`,
`presence_penalty`, `seed`, `response_format`. `stream()` automatically
requests usage info (`stream_options.include_usage`) on the final chunk.

## 20. Notifications

The framework ships `NotificationService` (fan-out dispatcher) and the
`NotificationChannel` interface in `app/Services/`, and two concrete channels
in `app/Notifications/`.

**Register channels** in `app/bootstrap.php`:

```php
NotificationService::registerChannel('telegram', new TelegramChannel());
NotificationService::registerChannel('webhook', new WebhookChannel(
    defaultUrl: 'https://hooks.example.com/notify',
));
```

**Dispatch** — one call reaches every registered channel:

```php
$notifier = new NotificationService();
$notifier->notify(
    userId: 42,
    fromUserId: null,
    type: 'subscription_expiring',
    title: 'Your plan expires soon',
    body: 'Renew before the 15th to keep access.',
    meta: ['chat_id' => -1001234567890],     // passed through to every channel
);
```

A channel that throws is isolated and logged — one broken integration never
blocks the others.

### TelegramChannel

Sends messages via the Bot API `sendMessage` endpoint. Token resolution: constructor
argument → `config('bot.token')`. Chat ID resolution: `$meta['chat_id']` → `$userId`.
Markdown parse mode by default.

```php
NotificationService::registerChannel('telegram', new TelegramChannel());
```

### WebhookChannel

POSTs a JSON payload (`{user_id, type, title, body, meta}`) to a configurable
URL. URL resolution: `$meta['webhook_url']` → constructor `$defaultUrl` →
`config('notifications.webhook.default_url')`.

```php
NotificationService::registerChannel('webhook', new WebhookChannel(
    defaultUrl: 'https://hooks.example.com/notify',
));
```

---

### Writing a custom channel

Implement `NotificationChannel` anywhere in your app:

```php
final class DiscordChannel implements NotificationChannel
{
    public function send(int $userId, string $type, string $title, string $body, array $meta = []): bool
    {
        // POST to Discord webhook, queue for delivery, etc.
    }
}
```

## 21. Testing

```bash
composer test    # phpunit
composer stan    # phpstan level 8
```

Tests run against SQLite in memory — no fixture database, no network. Because
responses *throw* rather than `exit()`, you assert on them directly:

```php
try {
    Response::ok(['x' => 1]);
    $this->fail('expected HttpResponse');
} catch (HttpResponse $r) {
    $this->assertSame(200, $r->status);
    $this->assertSame(['ok' => true, 'data' => ['x' => 1], 'error' => null], $r->payload);
}
```

Both gates must stay green before you commit.

## 22. Staying in sync with the framework

Fix a framework bug **once, in phpframe**, then push it to every project:

```bash
php setup/scripts/sync-framework.php /www/wwwroot/yourproject
```

It copies exactly the paths in `setup/framework-manifest.php` and touches nothing
else, so your controllers, models, config and routes are never overwritten. If
you ever find yourself editing a manifest path inside a project, stop: that change
will be silently reverted by the next sync. Make it upstream instead.

`setup/MIGRATION.md` covers bringing an existing (pre-O9) project onto the
framework.
