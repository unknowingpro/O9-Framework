<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal static service container. Bindings are closures or class names.
 *
 * - `bind()` registers a transient factory: every `make()` returns a fresh
 *   instance.
 * - `singleton()` registers a factory whose first `make()` is memoised and
 *   reused for the rest of the request.
 */
final class Container
{
    /** @var array<string, callable|string> */
    private static array $bindings = [];

    /** @var array<string, true> Marks which bindings are singletons. */
    private static array $shared = [];

    /** @var array<string, object> Memoised singleton instances. */
    private static array $instances = [];

    public static function bind(string $abstract, callable|string $concrete): void
    {
        self::$bindings[$abstract] = $concrete;
        unset(self::$shared[$abstract], self::$instances[$abstract]);
    }

    public static function singleton(string $abstract, callable|string $concrete): void
    {
        self::$bindings[$abstract] = $concrete;
        self::$shared[$abstract]   = true;
        // Drop any previously-cached instance so the new factory is honoured.
        unset(self::$instances[$abstract]);
    }

    public static function make(string $abstract): mixed
    {
        if (isset(self::$instances[$abstract])) {
            return self::$instances[$abstract];
        }
        $concrete = self::$bindings[$abstract] ?? $abstract;
        $object = is_callable($concrete) ? $concrete() : new $concrete();
        if (isset(self::$shared[$abstract])) {
            self::$instances[$abstract] = $object;
        }
        return $object;
    }

    public static function reset(): void
    {
        self::$bindings  = [];
        self::$shared    = [];
        self::$instances = [];
    }
}
