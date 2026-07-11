<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Standalone, pure input validator (pipe-delimited rules) usable anywhere — controllers AND
 * services. check() never exits/throws; it returns {valid, data, errors}. BaseController::validate()
 * wraps it to emit the 422 envelope. Rules: required, nullable, email, url, int, numeric, boolean,
 * array, min:N, max:N, in:a,b, regex:/.../, date, confirmed, and DB rules unique:tbl,col[,ignoreId]
 * and exists:tbl,col. Cast rules (int/numeric/boolean) coerce the returned value.
 * Apps add project rules via Validator::extend('slug', fn($field,$value,$param) => ...).
 */
final class Validator
{
    /** @var array<string, callable(string, mixed, ?string): ?string> */
    private static array $custom = [];

    /**
     * Register a custom rule. $fn receives (field, value, param) and returns an
     * error message or null. Referenced in rule strings as 'name' or 'name:param'.
     *
     * @param callable(string, mixed, ?string): ?string $fn
     */
    public static function extend(string $name, callable $fn): void
    {
        self::$custom[$name] = $fn;
    }

    /** @internal test reset */
    public static function resetExtensions(): void
    {
        self::$custom = [];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,string|list<string>> $rules
     * @return array{valid:bool,data:array<string,mixed>,errors:array<string,list<string>>}
     */
    public static function check(array $input, array $rules): array
    {
        $out = [];
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;
            // Trim string inputs so "  x  " / "  " normalise before validation + storage. Passwords
            // are excluded (leading/trailing spaces can be significant in a secret).
            if (is_string($value) && stripos($field, 'password') === false && !str_ends_with($field, '_confirmation')) {
                $value = trim($value);
            }
            $list = is_array($rule) ? $rule : explode('|', $rule);

            if (in_array('nullable', $list, true) && ($value === null || $value === '')) {
                $out[$field] = null;
                continue;
            }
            foreach ($list as $r) {
                if ($r === 'nullable') {
                    continue;
                }
                $err = self::applyRule($field, $r, $value, $input, $list);
                if ($err !== null && str_starts_with($err, 'STOP_')) {
                    $errors[$field][] = substr($err, 5); // required failed: record + halt this field
                    continue 2;
                }
                if ($err !== null) {
                    $errors[$field][] = $err;
                }
            }
            $out[$field] = $value;
        }
        return ['valid' => $errors === [], 'data' => $out, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $list the field's full rule list (so size rules know the declared type)
     * @return string|null error message, or 'STOP_…' to halt this field's chain, or null if ok.
     */
    private static function applyRule(string $field, string $r, mixed &$value, array $input, array $list = []): ?string
    {
        if ($r === 'required') {
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                return 'STOP_' . __('validation.required', ['field' => $field]);
            }
            return null;
        }
        if ($r === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : __('validation.email');
        }
        if ($r === 'url') {
            return filter_var((string) $value, FILTER_VALIDATE_URL) ? null : (__('validation.url') ?: 'Must be a valid URL.');
        }
        if ($r === 'int') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                return __('validation.integer');
            }
            $value = (int) $value;
            return null;
        }
        if ($r === 'numeric') {
            if (!is_numeric($value)) {
                return __('validation.numeric');
            }
            $value = (float) $value;
            return null;
        }
        if ($r === 'boolean') {
            $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($b === null) {
                return __('validation.boolean') ?: 'Must be true or false.';
            }
            $value = $b;
            return null;
        }
        if ($r === 'array') {
            return is_array($value) ? null : (__('validation.array') ?: 'Must be an array.');
        }
        if ($r === 'date') {
            // Require an absolute ISO-ish date — reject relative expressions (tomorrow, +1 week,
            // next tuesday) that strtotime() would otherwise happily accept into a date column.
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', (string) $value) !== 1
                || strtotime((string) $value) === false) {
                return __('validation.date') ?: 'Must be a valid date.';
            }
            return null;
        }
        if (str_starts_with($r, 'min:')) {
            $n = (int) substr($r, 4);
            return self::sizeOf($value, $list) < $n ? __('validation.min', ['n' => $n]) : null;
        }
        if (str_starts_with($r, 'max:')) {
            $n = (int) substr($r, 4);
            return self::sizeOf($value, $list) > $n ? __('validation.max', ['n' => $n]) : null;
        }
        if (str_starts_with($r, 'in:')) {
            return in_array((string) $value, explode(',', substr($r, 3)), true) ? null : __('validation.in');
        }
        if (str_starts_with($r, 'regex:')) {
            $pat = substr($r, 6);
            return @preg_match($pat, (string) $value) === 1 ? null : (__('validation.regex') ?: 'Invalid format.');
        }
        if ($r === 'confirmed') {
            return ($input[$field . '_confirmation'] ?? null) === $value ? null : (__('validation.confirmed') ?: 'Confirmation does not match.');
        }
        if (str_starts_with($r, 'unique:') || str_starts_with($r, 'exists:')) {
            return self::dbRule($r, $value);
        }

        // App-registered custom rules ('name' or 'name:param').
        $custName = $r;
        $custParam = null;
        if (str_contains($r, ':')) {
            [$custName, $custParam] = explode(':', $r, 2);
        }
        if (isset(self::$custom[$custName])) {
            return (self::$custom[$custName])($field, $value, $custParam);
        }

        // Unknown (non-empty) rule — likely a typo (e.g. 'rquired'). Surface it in debug so the
        // misconfiguration is visible; stays forward-compatible (no hard failure) in production.
        if ($r !== '' && (bool) config('app.debug', false) && class_exists(Logger::class)) {
            Logger::warning('validator.unknown_rule', ['rule' => $r, 'field' => $field]);
        }
        return null;
    }

    private static function dbRule(string $r, mixed $value): ?string
    {
        [$kind, $spec] = explode(':', $r, 2);
        $parts = explode(',', $spec);
        $table = $parts[0] ?? '';
        $col = $parts[1] ?? '';
        if (!self::ident($table) || !self::ident($col)) {
            return null; // refuse unsafe identifiers rather than risk injection
        }
        $sql = "SELECT COUNT(*) c FROM {$table} WHERE {$col} = ?";
        $args = [$value];
        if ($kind === 'unique' && isset($parts[2]) && $parts[2] !== '') {
            $sql .= ' AND id <> ?';
            $args[] = (int) $parts[2];
        }
        $row = Database::getInstance()->raw($sql, $args)->fetch();
        $count = (int) (is_array($row) ? ($row['c'] ?? 0) : 0);
        if ($kind === 'unique') {
            return $count === 0 ? null : (__('validation.unique') ?: 'Already taken.');
        }
        return $count > 0 ? null : (__('validation.exists') ?: 'Does not exist.');
    }

    /**
     * Laravel-style "size" for min/max: the numeric VALUE for a numeric field (declared int/numeric,
     * or already cast to int/float by a preceding rule), the element COUNT for an array, else the
     * character LENGTH. Keeps length semantics for strings — an all-digit password is still measured
     * by length, not read as a number.
     *
     * @param list<string> $list
     */
    private static function sizeOf(mixed $value, array $list): float
    {
        if (is_array($value)) {
            return (float) count($value);
        }
        $numericField = is_int($value) || is_float($value)
            || in_array('int', $list, true) || in_array('numeric', $list, true);
        if ($numericField && is_numeric($value)) {
            return (float) $value;
        }
        return (float) mb_strlen((string) $value);
    }

    private static function ident(string $s): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s) === 1;
    }
}
