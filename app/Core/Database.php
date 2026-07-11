<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * PDO wrapper exposing the connection + a tiny fluent query builder
 * (clone-per-call). Supports SQLite (default) and MySQL.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $driver;
    private int $savepointSeq = 0;

    private function __construct()
    {
        $name   = (string) config('database.default', 'sqlite');
        $conf   = (array) config("database.connections.$name", []);
        $this->driver = $name;
        $this->pdo = $this->connect($conf);
    }

    public static function getInstance(): Database
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Rebuild the underlying connection. Call after a fork() so each child opens
     * its own socket instead of sharing the parent's (which corrupts the protocol).
     */
    public function reconnect(): void
    {
        $name = (string) config('database.default', 'sqlite');
        $conf = (array) config("database.connections.$name", []);
        $this->driver = $name;
        $this->pdo = $this->connect($conf);
    }

    /** @param array<string, mixed> $conf */
    private function connect(array $conf): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            if (($conf['driver'] ?? 'sqlite') === 'sqlite') {
                $path = (string) ($conf['database'] ?? '');
                if ($path !== ':memory:' && !is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON;');
                $pdo->exec('PRAGMA journal_mode = WAL;');
                return $pdo;
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($conf['host'] ?? '127.0.0.1'),
                (int) ($conf['port'] ?? 3306),
                (string) ($conf['database'] ?? ''),
                (string) ($conf['charset'] ?? 'utf8mb4'),
            );
            $pdo = new PDO($dsn, (string) ($conf['username'] ?? ''), (string) ($conf['password'] ?? ''), $options);
            // UTC storage (project rule — timestamps written via gmdate) and a
            // relaxed sql_mode so inserts that SQLite tolerated (omitted
            // not-null-without-default, '' in numeric columns) don't hard-fail
            // under MySQL strict mode. utf8mb4 for emoji in user content.
            $pdo->exec("SET time_zone = '+00:00'");
            // sql_mode: relaxed by default (SQLite-tolerant). Set DB_STRICT=1 once
            // the app's column-defaults + FK hardening is in place.
            $mode = filter_var(env('DB_STRICT', 'false'), FILTER_VALIDATE_BOOL)
                ? 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'
                : 'NO_ENGINE_SUBSTITUTION';
            $pdo->exec("SET SESSION sql_mode = '$mode'");
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Run a parameterised query and return the PDOStatement.
     *
     * SQLite-only leading verbs are translated for MySQL at this single choke
     * point so `INSERT OR IGNORE` / `INSERT OR REPLACE` call sites stay
     * untouched and portable. The match is anchored to the statement start, so
     * it only ever rewrites the opening verb (never literal text mid-query).
     *
     * @param array<int|string, mixed> $params
     */
    public function raw(string $sql, array $params = []): PDOStatement
    {
        if ($this->driver === 'mysql') {
            $sql = (string) preg_replace('/^(\s*)INSERT\s+OR\s+IGNORE\b/i', '$1INSERT IGNORE', $sql, 1);
            $sql = (string) preg_replace('/^(\s*)INSERT\s+OR\s+REPLACE\b/i', '$1REPLACE', $sql, 1);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function table(string $name): QueryBuilder
    {
        return new QueryBuilder($this, $name);
    }

    public function tableExists(string $name): bool
    {
        if ($this->driver === 'sqlite') {
            $row = $this->raw(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$name]
            )->fetch();
            return $row !== false;
        }
        // MySQL `SHOW TABLES LIKE ?` does NOT accept a bound placeholder — use
        // information_schema (which does) so this is safe with a parameter.
        $row = $this->raw(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$name]
        )->fetch();
        return $row !== false;
    }

    /** True if $column exists on $table. Used for forward-compatible deploys. */
    public function columnExists(string $table, string $column): bool
    {
        if ($this->driver === 'sqlite') {
            foreach ($this->raw('PRAGMA table_info(' . $this->quoteId($table) . ')')->fetchAll() as $c) {
                if (($c['name'] ?? null) === $column) {
                    return true;
                }
            }
            return false;
        }
        $row = $this->raw(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            [$table, $column]
        )->fetch();
        return $row !== false;
    }

    /** @param array<string, mixed> $data */
    public function insertGetId(string $table, array $data): int
    {
        self::assertSafeIdentifier($table);
        $cols = array_keys($data);
        foreach ($cols as $c) {
            self::assertSafeIdentifier((string) $c);
        }
        $place = array_map(static fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteId($table),
            implode(', ', array_map([$this, 'quoteId'], $cols)),
            implode(', ', $place),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Quote a single, already-validated plain identifier for the active
     * engine (backticks on MySQL, double-quotes on SQLite). Guards future
     * reserved-word columns. Only call with literal/whitelisted names —
     * not qualified (`a.b`) names or expressions.
     */
    public function quoteId(string $ident): string
    {
        return $this->driver === 'mysql' ? "`$ident`" : "\"$ident\"";
    }

    /** Engine's random-ordering function: `RANDOM()` (SQLite) / `RAND()` (MySQL). */
    public function randomFunc(): string
    {
        return $this->driver === 'mysql' ? 'RAND()' : 'RANDOM()';
    }

    /**
     * SQL expression that buckets a datetime column into a stable
     * "YYYY-Www" key for weekly grouping. Both engines bucket by Sunday-based
     * week; numbers needn't match across engines, only be stable per week.
     */
    public function yearWeekExpr(string $column): string
    {
        return $this->driver === 'mysql'
            ? "DATE_FORMAT($column, '%X-W%V')"
            : "strftime('%Y-W%W', $column)";
    }

    /**
     * Guards SQL identifiers (table + column names) before they are
     * interpolated into a statement. Bound values handle data; identifiers
     * cannot be bound, so the only safe thing is to reject anything that
     * isn't a plain `[A-Za-z_][A-Za-z0-9_]*`. Callers should always use
     * literal/whitelisted names — this throws on anything else so a footgun
     * in higher-level code surfaces immediately instead of becoming SQLi.
     */
    public static function assertSafeIdentifier(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('unsafe SQL identifier: ' . $name);
        }
    }

    /**
     * Run $fn inside a transaction. Re-entrant via real SAVEPOINTs (supported
     * by both SQLite and MySQL/InnoDB): a nested call opens a savepoint so an
     * inner failure rolls back only its own work and re-throws, instead of
     * silently relying on the outer rollback. The outermost call is a normal
     * BEGIN/COMMIT/ROLLBACK.
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            $sp = 'sp_' . (++$this->savepointSeq);
            $this->pdo->exec("SAVEPOINT $sp");
            try {
                $result = $fn($this);
                $this->pdo->exec("RELEASE SAVEPOINT $sp");
                return $result;
            } catch (\Throwable $e) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT $sp");
                } catch (\PDOException) {
                    // The transaction (and its savepoints) may already be gone —
                    // MySQL DDL implicitly commits mid-transaction.
                }
                throw $e;
            }
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\PDOException) {
                // Already ended by an implicit commit (MySQL DDL) — nothing to undo.
            }
            throw $e;
        }
    }
}
