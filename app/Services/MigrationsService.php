<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Read + apply raw-SQL database migrations from disk (applied/available/
 * pending/applyAll, tracked in a `migrations` table).
 *
 * Migrations are numbered .sql files in setup/database/migrations/ (spec
 * layout); the path is overridable via config('database.migrations_path')
 * or the constructor (tests).
 *
 * DDL dialects diverge too much between MySQL and SQLite to share one file
 * (ENUM/AUTO_INCREMENT/ENGINE vs. SQLite's DDL and inline UNIQUE/INDEX
 * syntax), so a migration ships as driver-suffixed siblings —
 * `NNN_name.mysql.sql` and `NNN_name.sqlite.sql` — and available() returns
 * only the sibling matching the active connection's driver. A plain
 * `NNN_name.sql` with no driver suffix is treated as portable and applies
 * to every driver.
 */
final class MigrationsService
{
    private readonly Database $db;
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->db  = Database::getInstance();
        $this->dir = $dir ?? (string) config('database.migrations_path', base_path('setup/database/migrations'));
        $this->ensureMigrationsTable();
    }

    /** @return list<array{name: string, applied_at: string}> */
    public function applied(): array
    {
        // FETCH_ASSOC connection default guarantees the row shape.
        /** @var list<array{name: string, applied_at: string}> $rows */
        $rows = $this->db->raw('SELECT name, applied_at FROM migrations ORDER BY name')->fetchAll();
        return $rows;
    }

    /**
     * Migration filenames available on disk, sorted. Driver-suffixed
     * siblings (see class docblock) are filtered to the active driver; a
     * plain, unsuffixed `NNN_name.sql` applies to every driver.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $driver = $this->db->driver();
        $files = glob($this->dir . '/*.sql') ?: [];
        $files = array_values(array_filter($files, static function (string $path) use ($driver): bool {
            // Any single dot-segment right before .sql is a driver tag —
            // not just the two drivers this framework ships today. An
            // unrecognized tag (e.g. a future .postgres.sql, or a typo)
            // must be excluded here, not silently treated as portable.
            if (preg_match('/\.([a-z0-9]+)\.sql$/', basename($path), $m) === 1) {
                return $m[1] === $driver;
            }
            return true;
        }));
        sort($files);
        return array_map('basename', $files);
    }

    /** @return list<string> filenames not yet applied. */
    public function pending(): array
    {
        $applied = array_map(static fn (array $r): string => (string) $r['name'], $this->applied());
        return array_values(array_diff($this->available(), $applied));
    }

    /**
     * Apply every pending migration in order. Returns the names applied.
     *
     * Each migration file's statements are wrapped in a transaction so that a
     * partial failure leaves the DB unchanged for that file.
     *
     * NOTE (MySQL): DDL statements (CREATE TABLE, ALTER TABLE, etc.) cause an
     * implicit COMMIT in MySQL, so a transaction cannot fully roll back DDL.
     * The transaction still protects DML-only migrations and SQLite (where DDL
     * IS transactional). On MySQL a failed DDL migration may leave partial
     * schema changes; the error is re-thrown with a clear message so the
     * operator knows manual cleanup may be needed.
     *
     * @return list<string>
     */
    public function applyAll(): array
    {
        $pdo = $this->db->pdo();
        // Only SQLite has transactional DDL. On MySQL every DDL statement (CREATE/ALTER) triggers
        // an implicit COMMIT, which ends the transaction — a wrapping beginTransaction()/commit()
        // would then throw "no active transaction" and abort an otherwise-successful migration.
        // So wrap only on SQLite; on MySQL run unwrapped (DDL auto-commits) and guard with
        // inTransaction() throughout.
        $useTxn  = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $applied = [];
        foreach ($this->pending() as $name) {
            $sql = file_get_contents($this->dir . '/' . $name);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration file: {$name}");
            }
            if ($useTxn) { $pdo->beginTransaction(); }
            try {
                foreach ($this->splitStatements($sql) as $stmt) {
                    $pdo->exec($stmt);
                }
                $ins = $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)');
                $ins->execute([$name, gmdate('Y-m-d H:i:s')]);
                if ($pdo->inTransaction()) { $pdo->commit(); }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw new \RuntimeException(
                    "Migration '{$name}' failed"
                    . ($useTxn
                        ? ' and was rolled back'
                        : ' (MySQL: DDL may have partially auto-committed; manual cleanup may be needed)')
                    . ': ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
            $applied[] = $name;
        }
        return $applied;
    }

    private function ensureMigrationsTable(): void
    {
        // Engine clause only on MySQL — SQLite rejects it.
        $tail = $this->db->driver() === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . ' name VARCHAR(191) NOT NULL PRIMARY KEY,'
            . ' applied_at DATETIME NOT NULL'
            . ')' . $tail
        );
    }

    /**
     * Split a migration file into individual statements. `--` comments (leading
     * or inline) are stripped FIRST (a comment may itself contain a ';'), then the
     * remaining DDL — which has no semicolons inside statement bodies — is split
     * on ';'. (O9 migrations are plain DDL with no semicolon-bearing string
     * literals, so this lighter split is sufficient.)
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $sql = (string) preg_replace('/--[^\n]*/', '', $sql);
        $out = [];
        foreach (explode(';', $sql) as $chunk) {
            $stmt = trim($chunk);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
