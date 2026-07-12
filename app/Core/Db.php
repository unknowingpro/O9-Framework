<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Static query facade over the Database singleton. Models still extend
 * BaseModel/QueryBuilder for typical CRUD; this is the "hand-tuned raw SQL"
 * path, packaged as familiar static helpers (first/all/execute/insert/
 * upsert/nowExpr) for call sites that would rather not build a QueryBuilder
 * chain for a one-off query.
 */
final class Db
{
    /** The underlying PDO connection. */
    public static function connection(): PDO
    {
        return Database::getInstance()->pdo();
    }

    /** @param array<int|string, mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        return Database::getInstance()->raw($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return array_values(self::query($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Run an INSERT and return the new auto-increment id.
     *
     * @param array<int|string, mixed> $params
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) Database::getInstance()->pdo()->lastInsertId();
    }

    /**
     * Run a write statement and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function driver(): string
    {
        return Database::getInstance()->driver();
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /** SQL expression for the current unix timestamp, dialect-aware. */
    public static function nowExpr(): string
    {
        return self::isMysql() ? 'UNIX_TIMESTAMP()' : "strftime('%s','now')";
    }

    /**
     * Dialect-aware upsert.
     *
     * @param list<string> $conflictKeys
     * @param array<string, string> $updateExpressions col => raw expr (no binding)
     * @param array<string, mixed> $insertData
     */
    public static function upsert(string $table, array $conflictKeys, array $updateExpressions, array $insertData): void
    {
        $cols = array_keys($insertData);
        Database::assertSafeIdentifier($table);
        foreach ($cols as $c) {
            Database::assertSafeIdentifier($c);
        }
        foreach ($conflictKeys as $k) {
            Database::assertSafeIdentifier($k);
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList      = implode(',', $cols);

        if (self::isMysql()) {
            $colListQuoted = implode(',', array_map(static fn (string $c): string => "`$c`", $cols));
            $updates = implode(', ', array_map(
                static fn (string $c, string $e): string => "`$c`=$e",
                array_keys($updateExpressions),
                $updateExpressions
            ));
            $sql = "INSERT INTO `$table` ($colListQuoted) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        } else {
            $updates = implode(', ', array_map(
                static fn (string $c, string $e): string => "$c=$e",
                array_keys($updateExpressions),
                $updateExpressions
            ));
            $conflicts = implode(',', $conflictKeys);
            $sql = "INSERT INTO $table ($colList) VALUES ($placeholders) ON CONFLICT($conflicts) DO UPDATE SET $updates";
        }

        self::query($sql, array_values($insertData));
    }

    /** Reset the underlying connection (e.g. after a fork). */
    public static function reconnect(): void
    {
        Database::getInstance()->reconnect();
    }
}
