<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Clone-per-call fluent query builder so chained calls never leak state
 * between requests. Built for the common cases — SELECT/INSERT/UPDATE/DELETE.
 */
final class QueryBuilder
{
    private string $table;
    private Database $db;
    /** @var list<array{bool: string, sql: string}> */
    private array $wheres = [];
    /** @var list<mixed> */
    private array $bindings = [];
    /** @var list<string> */
    private array $orders = [];
    /** @var list<string> */
    private array $selects = ['*'];
    private ?int $limit = null;
    private ?int $offset = null;
    /** @var list<string> */
    private array $joins = [];
    /** @var list<string> */
    private array $groupBy = [];
    /** @var list<string> */
    private array $havings = [];

    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function select(string ...$columns): self
    {
        $c = clone $this;
        $c->selects = $columns !== [] ? array_values($columns) : ['*'];
        return $c;
    }

    /**
     * Operators allowed in a WHERE clause. Values are always bound, so this is
     * just to stop an operator string from carrying arbitrary SQL.
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    public function where(string $column, string $op, mixed $value): self
    {
        $column = $this->column($column);
        return $this->pushCondition('AND', "$column {$this->operator($op)} ?", [$value]);
    }

    /** OR-joined counterpart to where(). The first clause's connector is ignored. */
    public function orWhere(string $column, string $op, mixed $value): self
    {
        $column = $this->column($column);
        return $this->pushCondition('OR', "$column {$this->operator($op)} ?", [$value]);
    }

    public function whereNull(string $column): self
    {
        return $this->pushCondition('AND', $this->column($column) . ' IS NULL', []);
    }

    public function whereNotNull(string $column): self
    {
        return $this->pushCondition('AND', $this->column($column) . ' IS NOT NULL', []);
    }

    /** @param array<int|string, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            return $this->pushCondition('AND', '1 = 0', []); // empty set → no rows
        }
        $column       = $this->column($column);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        return $this->pushCondition('AND', "$column IN ($placeholders)", array_values($values));
    }

    /** GROUP BY one or more validated columns. */
    public function groupBy(string ...$columns): self
    {
        $c = clone $this;
        foreach ($columns as $col) {
            $c->groupBy[] = $c->column($col);
        }
        return $c;
    }

    /** Raw HAVING clause — a literal expression (no bound parameters); caller owns its safety. */
    public function havingRaw(string $sql): self
    {
        $c = clone $this;
        $c->havings[] = $sql;
        return $c;
    }

    /**
     * Raw WHERE with optional bound parameters; caller owns the SQL's safety (use for expressions).
     *
     * @param array<int|string, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        return $this->pushCondition('AND', $sql, array_values($bindings));
    }

    /** Raw SELECT expression list, e.g. selectRaw('user_id', 'COUNT(*) AS c'); caller owns safety. */
    public function selectRaw(string ...$expressions): self
    {
        $c = clone $this;
        $c->selects = $expressions !== [] ? array_values($expressions) : ['*'];
        return $c;
    }

    /** Raw ORDER BY clause; caller owns safety. */
    public function orderByRaw(string $sql): self
    {
        $c = clone $this;
        $c->orders[] = $sql;
        return $c;
    }

    /**
     * Validate a column reference (`col` or `table.col`) — each segment must be
     * a plain SQL identifier. Identifiers can't be bound, so anything that
     * isn't whitelist-shaped is rejected rather than interpolated as SQLi.
     */
    private function column(string $column): string
    {
        foreach (explode('.', $column) as $part) {
            Database::assertSafeIdentifier($part);
        }
        return $column;
    }

    /** Validate an operator against the whitelist (case-insensitive for LIKE). */
    private function operator(string $op): string
    {
        $op = strtoupper(trim($op));
        if (!in_array($op, self::OPERATORS, true)) {
            throw new \RuntimeException('unsafe SQL operator: ' . $op);
        }
        return $op;
    }

    /**
     * Append a WHERE fragment + its bindings, preserving call order. SQL
     * fragments and bindings are pushed in lockstep so positional `?` stays
     * aligned with $this->bindings.
     *
     * @param list<mixed> $bindings
     */
    private function pushCondition(string $bool, string $sql, array $bindings): self
    {
        $c = clone $this;
        $c->wheres[] = ['bool' => $bool, 'sql' => $sql];
        foreach ($bindings as $b) {
            $c->bindings[] = $b;
        }
        return $c;
    }

    public function orderBy(string $column, string $dir = 'ASC'): self
    {
        $c = clone $this;
        $c->orders[] = $this->column($column) . ' ' . (strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC');
        return $c;
    }

    public function limit(int $n): self
    {
        $c = clone $this;
        $c->limit = $n;
        return $c;
    }

    public function offset(int $n): self
    {
        $c = clone $this;
        $c->offset = $n;
        return $c;
    }

    public function join(string $other, string $first, string $op, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new \RuntimeException('unsafe JOIN type: ' . $type);
        }
        // Validate every identifier so a JOIN condition can never be a SQL-injection vector. The
        // table may carry an alias ("users u") — assert each whitespace-separated part; columns may
        // be alias-qualified ("u.id") — column() handles the dotted form; the operator is allow-listed.
        $tableParts = preg_split('/\s+/', trim($other)) ?: [];
        foreach ($tableParts as $p) {
            Database::assertSafeIdentifier($p);
        }
        $c = clone $this;
        $c->joins[] = $type . ' JOIN ' . implode(' ', $tableParts) . ' ON '
            . $this->column($first) . ' ' . $this->operator($op) . ' ' . $this->column($second);
        return $c;
    }

    private function buildWhere(): string
    {
        if ($this->wheres === []) {
            return '';
        }
        $sql = '';
        foreach ($this->wheres as $i => $w) {
            $sql .= $i === 0 ? $w['sql'] : ' ' . $w['bool'] . ' ' . $w['sql'];
        }
        return ' WHERE ' . $sql;
    }

    private function buildGroup(): string
    {
        $sql = '';
        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }
        return $sql;
    }

    private function buildTail(): string
    {
        $sql = '';
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /** @return list<mixed> */
    private function whereOnlyBindings(): array
    {
        // bindings from whereIn are already collected in $this->bindings
        return $this->bindings;
    }

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->table;
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        $sql .= $this->buildWhere() . $this->buildGroup() . $this->buildTail();
        // The connection is opened with FETCH_ASSOC (see Database::connect),
        // so every row is an assoc array — a fact static analysis can't see.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->raw($sql, $this->whereOnlyBindings())->fetchAll();
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $row = $this->limit(1)->get();
        return $row[0] ?? null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . $this->table . $this->buildWhere();
        $row = $this->db->raw($sql, $this->whereOnlyBindings())->fetch();
        return (int) (is_array($row) ? ($row['c'] ?? 0) : 0);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            Database::assertSafeIdentifier((string) $k);
            $sets[] = $this->db->quoteId((string) $k) . ' = ?';
            $params[] = $v;
        }
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . $this->buildWhere();
        $params = array_merge($params, $this->whereOnlyBindings());
        return $this->db->raw($sql, $params)->rowCount();
    }

    public function delete(): int
    {
        $sql = 'DELETE FROM ' . $this->table . $this->buildWhere();
        return $this->db->raw($sql, $this->whereOnlyBindings())->rowCount();
    }

    /**
     * Insert one row (assoc) or many (list of assoc) in a single statement.
     * Returns the affected row count. Every row must share the same column
     * set as the first; columns are validated as safe identifiers. For a
     * single insert where you need the new id, use Database::insertGetId().
     *
     * @param array<int|string, mixed> $rows
     */
    public function insert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        // Normalise a single assoc row into a one-element list.
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }
        /** @var list<array<string, mixed>> $rows */
        $columns = array_keys($rows[0]);
        foreach ($columns as $col) {
            Database::assertSafeIdentifier((string) $col);
        }
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }
        $sql = 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', array_map([$this->db, 'quoteId'], array_map('strval', $columns))) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $rowPlaceholder));
        return $this->db->raw($sql, $params)->rowCount();
    }

    /**
     * Insert rows, updating $updateColumns on conflict against $uniqueBy.
     * Driver-aware: SQLite uses ON CONFLICT … DO UPDATE (excluded.col),
     * MySQL uses ON DUPLICATE KEY UPDATE (VALUES(col)). Returns affected rows.
     *
     * @param array<int|string, mixed> $rows
     * @param list<string>             $uniqueBy
     * @param list<string>             $updateColumns
     */
    public function upsert(array $rows, array $uniqueBy, array $updateColumns): int
    {
        if ($rows === []) {
            return 0;
        }
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }
        /** @var list<array<string, mixed>> $rows */
        $columns = array_map('strval', array_keys($rows[0]));
        foreach (array_merge($columns, $uniqueBy, $updateColumns) as $col) {
            Database::assertSafeIdentifier($col);
        }
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }
        $q = fn (string $c): string => $this->db->quoteId($c);
        $sql = 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', array_map($q, $columns)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        if ($this->db->driver() === 'mysql') {
            $sets = array_map(static fn (string $c): string => $q($c) . ' = VALUES(' . $q($c) . ')', $updateColumns);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        } else {
            $sets = array_map(static fn (string $c): string => $q($c) . ' = excluded.' . $q($c), $updateColumns);
            $sql .= ' ON CONFLICT (' . implode(', ', array_map($q, $uniqueBy)) . ') DO UPDATE SET '
                . implode(', ', $sets);
        }
        return $this->db->raw($sql, $params)->rowCount();
    }
}
