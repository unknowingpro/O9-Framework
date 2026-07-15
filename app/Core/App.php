<?php
declare(strict_types=1);

namespace App\Core;

/**
 * App singleton: configures the runtime, applies the kernel gates, registers
 * routes, and dispatches the incoming request through the Router.
 *
 * Boot order (each gate optional / config-driven):
 *   errors → secure-config assert → force-https → security headers →
 *   session (web only) → app bootstrap hook → CORS (preflight short-circuit) →
 *   maintenance → geo-block → API context → conditional route loading →
 *   dispatch → emit (thrown HttpResponse / HttpException rendered uniformly).
 *
 * App-level bootstrap (container bindings, event listeners) belongs in
 * app/bootstrap.php — required once per request when present. Never
 * instantiate App directly; always use getInstance().
 */
class App
{
    private static ?App $instance = null;
    private static bool $debugErrors = false;
    private Router $router;
    private Request $request;

    private function __construct()
    {
        $this->router = new Router();
        $this->request = new Request();
    }

    public static function getInstance(): App
    {
        return self::$instance ??= new self();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function run(): void
    {
        try {
            $this->configureErrors();
            $this->assertSecureConfig();
            $this->maybeForceHttps();
            $this->sendSecurityHeaders();
            // API requests are stateless (API key / Bearer) — no PHP session, no Set-Cookie, no session lock.
            if (!str_starts_with($this->request->path(), '/api/')) {
                $this->startSession();
            }

            $this->bootApp();

            // CORS for the JSON API; a handled OPTIONS preflight ends the request here.
            if (Cors::handle($this->request)) {
                return;
            }

            // Maintenance mode: 503 to everyone except admins (who keep full access
            // so they can fix things) and the auth routes (so they can log in).
            if (Maintenance::shouldBlock($this->request)) {
                Maintenance::serve($this->request);   // throws HttpResponse
            }

            // Geo-blocking: refuse visitors from blocked countries (451).
            // CDN-edge-header based; fails open when no country is known.
            if (GeoBlock::shouldBlock($this->request)) {
                GeoBlock::serve($this->request);      // throws HttpResponse
            }

            // API correlation id + per-request access logging (captured at shutdown so
            // the final status/duration are known even though Response methods exit()).
            if (str_starts_with($this->request->path(), '/api/')) {
                ApiContext::begin($this->request->header('X-Request-Id') ?: null);
                register_shutdown_function([ApiContext::class, 'finish']);
            }

            $this->loadRoutes();
            $this->router->dispatch($this->request);
        } catch (HttpResponse $response) {
            // Any layer may short-circuit by throwing a finished response.
            $response->send();
        }
    }

    /**
     * App-level bootstrap hook. Wires the app's event listeners and requires
     * app/bootstrap.php (container bindings, gate config) when present, then
     * resolves the locale once so direction() is correct everywhere.
     */
    private function bootApp(): void
    {
        date_default_timezone_set((string) config('app.timezone', 'UTC'));
        if (class_exists(EventListeners::class)) {
            EventListeners::register();
        }
        $bootstrap = base_path('app/bootstrap.php');
        if (is_file($bootstrap)) {
            require $bootstrap;
        }
        if (class_exists(Lang::class)) {
            Lang::locale();
        }
    }

    /**
     * Global PHP error handler — keeps warnings/notices out of the response body.
     * Honours the @ operator (suppressed → swallowed silently); logs real warnings and,
     * in production, swallows their display (in debug, returns false so PHP also shows them).
     * Recursion-guarded so a logging failure can't loop. Public for testing.
     *
     * @internal
     */
    public static function handlePhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        if ($errno === E_USER_ERROR) {
            return false; // user-fatal: delegate to PHP so it halts execution as intended
        }
        if (!(error_reporting() & $errno)) {
            return true; // @-suppressed or below the reporting threshold — swallow, never display
        }
        static $inHandler = false;
        if (!$inHandler) {
            $inHandler = true;
            try {
                if (class_exists(Logger::class)) {
                    Logger::warning('php', ['errno' => $errno, 'msg' => $errstr, 'where' => $errfile . ':' . $errline]);
                }
            } catch (\Throwable) {
                // Logging a PHP warning must never itself raise.
            }
            $inHandler = false;
        }
        return !self::$debugErrors; // prod: swallow display (already logged); dev: let PHP also show it
    }

    /** Refuse to boot production with a weak or placeholder JWT secret. */
    private function assertSecureConfig(): void
    {
        if (config('app.env') !== 'production') {
            return;
        }
        $secret = (string) config('app.jwt.secret', '');
        $weak = ['', 'change-me', 'change-me-to-a-long-random-string', 'dev-secret-please-rotate-in-production'];
        if (in_array($secret, $weak, true) || strlen($secret) < 32) {
            http_response_code(500);
            exit('Refusing to start: set a strong JWT_SECRET (>=32 chars) in production.');
        }
    }

    /**
     * Redirect plain HTTP to HTTPS in production — but only on a DEFINITIVE
     * plain-http signal, so it can never loop when TLS is terminated upstream and
     * the proxy doesn't forward the scheme. We redirect when either the proxy
     * explicitly says X-Forwarded-Proto: http, or there is no proxy at all
     * (no XFP/XFF) and HTTPS is off. When the scheme is unknown behind a proxy we
     * do nothing and rely on HSTS + the web-server redirect. GET/HEAD only, so a
     * POST body is never silently dropped. Disable via app.force_https = false.
     */
    private function maybeForceHttps(): void
    {
        if (!config('app.force_https', true) || config('app.env') !== 'production' || headers_sent()) {
            return;
        }
        if (!in_array($this->request->method(), ['GET', 'HEAD'], true)) {
            return;
        }
        $xfp     = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $behindProxy = $xfp !== '' || !empty($_SERVER['HTTP_X_FORWARDED_FOR']);
        $definitelyHttp = $xfp === 'http' || (!$behindProxy && !$isHttps);
        if (!$definitelyHttp) {
            return;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return;
        }
        // header() rejects CR/LF, so a forged Host/URI can't inject extra headers.
        header('Location: https://' . $host . (string) ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    /**
     * Emit security response headers for every request, before any output.
     *
     * Baseline (all responses): Referrer-Policy, nosniff, X-Frame-Options, and —
     * over HTTPS in production — HSTS. HTML/page responses additionally get a
     * Content-Security-Policy.
     *
     * CSP note: server-rendered admin panels in the O9 apps rely on inline
     * <script>/<style>, so 'unsafe-inline' is part of the default; the policy
     * still blocks attacker-hosted external scripts, framing by other origins,
     * <base> hijacking, plugin/object injection, and cross-origin form posts.
     * Override via config('security.csp') when the app's resource set changes.
     */
    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        // Don't advertise the runtime/version (recon aid for attackers).
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        $https = !empty($_SERVER['HTTPS'])
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || config('app.env') === 'production';
        if ($https) {
            // 1 year; submit to the preload list only after confirming every
            // subdomain is HTTPS — left off includeSubDomains/preload by default.
            header('Strict-Transport-Security: max-age=31536000');
        }

        // CSP only on non-API document responses (harmless on JSON, but pointless).
        if (str_starts_with($this->request->path(), '/api/')) {
            return;
        }
        $default = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "font-src 'self' data:; "
            . "img-src 'self' data: blob: https:; "
            . "media-src 'self' blob: https:; "
            . "connect-src 'self' https:; "
            . "worker-src 'self' blob:; "
            . "object-src 'self'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'self'; "
            . "form-action 'self'";
        header('Content-Security-Policy: ' . (string) config('security.csp', $default));
    }

    private function configureErrors(): void
    {
        $debug = (bool) config('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));

        // Route PHP warnings/notices through the logger and NEVER to the output stream.
        // Under PHP-FPM (display_errors=stdout), an @-suppressed warning emitted at
        // request-shutdown — e.g. the access logger when storage/logs is unwritable —
        // can otherwise reach the body and corrupt a JSON response. See handlePhpError().
        self::$debugErrors = $debug;
        set_error_handler([self::class, 'handlePhpError']);

        // Fatal errors (OOM, timeouts, parse/compile) never reach set_exception_handler — catch them
        // at shutdown so they're logged and the client gets a structured 500 instead of a blank page.
        register_shutdown_function(function () use ($debug): void {
            $err = error_get_last();
            if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $ref = strtoupper(bin2hex(random_bytes(4)));
            if (class_exists(Logger::class)) {
                Logger::error('fatal', ['ref' => $ref, 'message' => $err['message'], 'where' => $err['file'] . ':' . $err['line']], 'error');
            }
            if (headers_sent()) {
                return;
            }
            http_response_code(500);
            if ($this->request->wantsJson()) {
                // Response::json() throws HttpResponse rather than exiting (see
                // Response.php) — a throw escaping a shutdown-function callback is
                // NOT routed to set_exception_handler (verified empirically), so
                // it must be caught and sent right here or a fatal error would
                // silently produce PHP's raw uncaught-exception output instead of
                // this structured 500.
                try {
                    Response::json(['ok' => false, 'data' => null, 'error' => [
                        'code' => 'server_error',
                        'message' => $debug ? (string) $err['message'] : 'Internal server error',
                        'ref' => $ref,
                    ]], 500);
                } catch (HttpResponse $r) {
                    $r->send();
                }
                return;
            }
            echo '<!doctype html><meta charset="utf-8"><title>500</title>'
                . '<div style="font-family:sans-serif;padding:48px;text-align:center"><h1>500</h1><p>Something went wrong.</p>'
                . '<p style="color:#888;font-size:13px">Reference: ' . $ref . '</p></div>';
        });

        set_exception_handler(function (\Throwable $e) use ($debug): void {
            // An uncaught thrown HttpResponse is a finished response — emit it as-is.
            if ($e instanceof HttpResponse) {
                $e->send();
                return;
            }
            // A thrown HttpException carries its own status/code/safe-message; any
            // other throwable is an unexpected fault → 500. Only server faults get
            // a reference id + an ERROR row in system_log; expected client errors
            // (404/403/422 thrown by a controller) are logged file-only, not as faults.
            $http     = $e instanceof HttpException ? $e : null;
            $status   = $http !== null ? $http->status : 500;
            $code     = $http !== null ? $http->errorCode : 'server_error';
            $details  = $http !== null ? $http->details : null;
            $isServer = $status >= 500;
            $ref      = $isServer ? strtoupper(bin2hex(random_bytes(4))) : '';

            if (class_exists(Logger::class)) {
                if ($isServer) {
                    Logger::exception($e, $ref !== '' ? ['ref' => $ref] : []);   // ERROR → file + system_log
                } else {
                    Logger::info($code, ['status' => $status, 'detail' => $e->getMessage()], 'http');   // expected, file-only
                }
            }

            $userMsg = $http ? $http->userMessage() : ($debug ? $e->getMessage() : 'Internal server error');

            if ($this->request->wantsJson()) {
                $err = ['code' => $code, 'message' => $userMsg];
                if ($details !== null)            { $err['details'] = $details; }
                if ($ref !== '')                  { $err['ref'] = $ref; }
                if ($debug && $isServer)          { $err['trace'] = explode("\n", $e->getTraceAsString()); }
                // Response::json() throws HttpResponse rather than exiting — a throw
                // escaping this handler is NOT re-delivered to it (verified
                // empirically: PHP treats that as an uncaught fatal, not a nested
                // handler call), so it must be caught and sent right here.
                try {
                    Response::json(['ok' => false, 'data' => null, 'error' => $err], $status);
                } catch (HttpResponse $r) {
                    $r->send();
                }
                return;
            }
            http_response_code($status);
            // Debug + real fault → raw message + trace for the developer. Otherwise a
            // styled page through the normal layout. The render itself is wrapped: if
            // the layout is what just failed, emit a minimal self-contained fallback.
            if ($debug && $isServer) {
                echo '<pre style="padding:24px;font-family:monospace">'
                    . htmlspecialchars($e->getMessage())
                    . "\n\n" . htmlspecialchars($e->getTraceAsString())
                    . '</pre>';
                return;
            }
            try {
                if (!function_exists('view')) {
                    throw new \RuntimeException('view() unavailable');
                }
                echo view('pages/error', ['status' => $status, 'message' => $userMsg, 'ref' => $ref]);
            } catch (\Throwable $inner) {
                echo '<!doctype html><meta charset="utf-8"><title>' . $status . '</title>'
                    . '<body style="font-family:system-ui,sans-serif;'
                    . 'display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
                    . '<div><h1 style="font-size:48px;margin:0">' . $status . '</h1>'
                    . '<p>' . htmlspecialchars($userMsg) . '</p>'
                    . ($ref !== '' ? '<p style="color:#888;font-size:13px">Reference: ' . $ref . '</p>' : '')
                    . '<a href="/">Back to home</a></div></body>';
            }
        });
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        // In production force Secure cookies regardless of the proxy header
        // (TLS is terminated upstream, so $_SERVER['HTTPS'] may be empty).
        $secure = config('app.env') === 'production'
            ? true
            : (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name((string) config('app.session_name', 'o9_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'samesite' => 'Lax',
            'httponly' => true,
            'secure'   => $secure,
        ]);
        // Redis-backed sessions when configured + reachable, so the app tier is
        // stateless behind a load balancer. Falls back to native file sessions.
        // (Wired by the Cache subsystem — no-op until Cache/RedisSessionHandler exists.)
        $this->registerRedisSessionHandler();
        session_start();
        $this->enforceSessionTimeouts();
    }

    /**
     * Swap PHP's session store for Redis when config('cache.session.driver')
     * is 'redis' and the connection is reachable; native file sessions
     * otherwise (with a throttled log warning on degradation).
     */
    private function registerRedisSessionHandler(): void
    {
        if (config('cache.session.driver') !== 'redis') {
            return;
        }
        $redis = Cache\RedisConnection::get();
        if ($redis === null) {
            // configured for redis but unreachable → native file sessions
            Cache\RedisConnection::warnFallback('session');
            return;
        }
        session_set_save_handler(
            new Cache\RedisSessionHandler(
                $redis,
                (int) config('cache.session.ttl', 1209600),
                (string) config('cache.prefix', 'o9:'),
            ),
            true,
        );
    }

    /**
     * Idle + absolute session timeouts, enforced server-side on every web request.
     *
     *   idle     — 30 min of inactivity ends the session.
     *   absolute — 8 h hard cap regardless of activity (a stolen long-lived
     *              cookie can't outlive this).
     *
     * Both are checked against server timestamps stored in the session, not the
     * client. On expiry the session is fully cleared + the id regenerated, so the
     * old session can't be reused and the user is bounced to a clean re-login.
     * Only acts on authenticated sessions (an anonymous browse session is left
     * alone so we don't thrash session ids for logged-out visitors).
     */
    private function enforceSessionTimeouts(): void
    {
        $idle     = (int) config('app.session_idle_ttl', 1800);      // 30 min
        $absolute = (int) config('app.session_absolute_ttl', 28800); // 8 h
        $now      = time();

        if (!isset($_SESSION['user_id'])) {
            return; // not authenticated — nothing to expire
        }

        $started  = (int) ($_SESSION['_auth_started'] ?? $now);
        $lastSeen = (int) ($_SESSION['_auth_seen'] ?? $now);

        $reason = self::sessionExpiry($started, $lastSeen, $now, $idle, $absolute);
        if ($reason !== null) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['_expired'] = $reason;
            return;
        }

        // Live session — slide the idle window; seed the absolute start once.
        $_SESSION['_auth_started'] ??= $now;
        $_SESSION['_auth_seen'] = $now;
    }

    /**
     * Pure timeout decision: 'idle' | 'absolute' | null. Idle is checked first
     * (the more common expiry). A zero/negative limit disables that check.
     */
    public static function sessionExpiry(int $started, int $lastSeen, int $now, int $idle, int $absolute): ?string
    {
        if ($idle > 0 && ($now - $lastSeen) > $idle)     { return 'idle'; }
        if ($absolute > 0 && ($now - $started) > $absolute) { return 'absolute'; }
        return null;
    }

    /**
     * Load only the route file the request needs — an /api/* request never
     * matches a web route and vice versa, so building the other table is pure
     * waste. Bot webhooks (default prefix /webhook) load routes/bot.php.
     *
     * Route files may either use $router directly (it is in scope) or return a
     * closure that receives it — both project styles are supported.
     */
    private function loadRoutes(): void
    {
        $router = $this->router;
        $path   = $this->request->path();

        $botPrefix = (string) config('app.bot_route_prefix', '/webhook');
        // Match the bot prefix on a path boundary, not as a bare substring, so
        // '/webhook-status' (a distinct web route) doesn't load the bot routes
        // just because it starts with '/webhook'.
        $isBotPath = $botPrefix !== ''
            && ($path === $botPrefix || str_starts_with($path, rtrim($botPrefix, '/') . '/'));
        if (str_starts_with($path, '/api/')) {
            $file = base_path('routes/api.php');
        } elseif ($isBotPath && is_file(base_path('routes/bot.php'))) {
            $file = base_path('routes/bot.php');
        } else {
            $file = base_path('routes/web.php');
        }
        if (!is_file($file)) {
            return;
        }
        $ret = require $file;
        if (is_callable($ret)) {
            $ret($router);
        }
    }
}
