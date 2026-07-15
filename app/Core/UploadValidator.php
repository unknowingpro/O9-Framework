<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validate an uploaded file (a single $_FILES entry) against MIME-type and
 * size constraints. Uses finfo (fileinfo) to sniff the real content type
 * rather than trusting the client-supplied extension or the browser's MIME.
 *
 * Usage:
 *   $result = UploadValidator::validate($_FILES['avatar'], [
 *       'mimes'     => ['jpg', 'jpeg', 'png', 'webp'],
 *       'max_size'  => 2048,            // KB (default 2048)
 *       'required'  => true,            // whether a missing upload is an error
 *   ]);
 *
 *   if (!$result['valid']) { /* use $result['errors'] * / }
 *
 * Returns the same {valid, data, errors} shape as the framework's Validator.
 *
 * @see \App\Core\MediaFilenameHelper for safe filename generation
 */
final class UploadValidator
{
    /**
     * Validate a single upload entry from $_FILES.
     *
     * @param array<string, mixed>|null $file  A single $_FILES entry, e.g. $_FILES['avatar'], or null.
     *        Expected keys: name, tmp_name, error, size (standard PHP upload array).
     * @param array<string, mixed> $rules
     *        mimes     — list of allowed extensions (e.g. ['jpg', 'png']).
     *                    When empty or absent, no MIME filtering is applied.
     *        max_size  — max file size in KB (default 2048).
     *        required  — bool, whether a missing/null file is an error (default true).
     *
     * @return array{valid: bool, data: array<string, mixed>|null, errors: list<string>}
     */
    public static function validate(?array $file, array $rules = []): array
    {
        $errors   = [];
        $mimes    = self::normList($rules['mimes'] ?? []);
        $maxSize  = (int) ($rules['max_size'] ?? 2048) * 1024; // KB → bytes
        $required = (bool) ($rules['required'] ?? true);

        // ── No file at all ───────────────────────────────────────────────────
        if ($file === null || !isset($file['tmp_name']) || $file['tmp_name'] === '') {
            if ($required) {
                $errors[] = self::msg('validation.required_file', 'A file is required.');
            }
            return ['valid' => $errors === [], 'data' => null, 'errors' => $errors];
        }

        // ── PHP upload error ─────────────────────────────────────────────────
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = self::uploadError($errorCode);
            return ['valid' => false, 'data' => null, 'errors' => $errors];
        }

        $tmpPath   = (string) $file['tmp_name'];
        $origName  = (string) ($file['name'] ?? '');
        $sizeBytes = (int) ($file['size'] ?? 0);

        // ── Size check ───────────────────────────────────────────────────────
        if ($maxSize > 0 && $sizeBytes > $maxSize) {
            $maxKb = $maxSize / 1024;
            $errors[] = self::msg('validation.max_file', "File exceeds {$maxKb} KB.");
            return ['valid' => false, 'data' => null, 'errors' => $errors];
        }

        // ── MIME check ───────────────────────────────────────────────────────
        if ($mimes !== []) {
            $ext = MediaFilenameHelper::extension($origName);
            if (!in_array($ext, $mimes, true)) {
                $errors[] = self::msg('validation.mimes', 'Allowed types: ' . implode(', ', $mimes) . '.');
                return ['valid' => false, 'data' => null, 'errors' => $errors];
            }

            // Sniff the real content type — reject files whose magic bytes don't
            // match their claimed extension (e.g. a .png that is really a .exe).
            $detected = MediaFilenameHelper::detectMime($tmpPath, $origName);
            $expected = MediaFilenameHelper::guessMime($origName);
            if ($detected !== $expected && $detected !== 'application/octet-stream') {
                $errors[] = self::msg('validation.mimes', 'File content does not match its extension.');
                return ['valid' => false, 'data' => null, 'errors' => $errors];
            }
        }

        return [
            'valid'  => true,
            'data'   => [
                'tmp_name' => $tmpPath,
                'name'     => $origName,
                'size'     => $sizeBytes,
                'mime'     => MediaFilenameHelper::detectMime($tmpPath, $origName),
                'ext'      => MediaFilenameHelper::extension($origName),
            ],
            'errors' => [],
        ];
    }

    /**
     * Convenience: returns true when the upload is valid, false otherwise.
     *
     * @param array<string, mixed>|null $file  A single $_FILES entry, or null.
     * @param array<string, mixed>      $rules See validate() for the accepted keys.
     */
    public static function passes(?array $file, array $rules = []): bool
    {
        return self::validate($file, $rules)['valid'];
    }

    /**
     * Convenience: returns the validated data array, or null on failure.
     *
     * @param array<string, mixed>|null $file  A single $_FILES entry, or null.
     * @param array<string, mixed>      $rules See validate() for the accepted keys.
     * @return array<string, mixed>|null
     */
    public static function validData(?array $file, array $rules = []): ?array
    {
        $result = self::validate($file, $rules);
        return $result['valid'] ? $result['data'] : null;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Return a translated or plain-English message.
     * __() returns the key itself when no translation file is loaded, so we
     * fall back when the return equals the key rather than using ?:.
     */
    private static function msg(string $key, string $fallback): string
    {
        $t = __($key);
        return $t !== $key && $t !== '' ? $t : $fallback;
    }

    /**
     * Normalize a list value — always return an array of trimmed lowercase strings.
     *
     * @param mixed $list
     * @return list<string>
     */
    private static function normList(mixed $list): array
    {
        if (!is_array($list)) {
            $list = explode(',', (string) $list);
        }
        return array_values(array_filter(array_map('strtolower', array_map('trim', $list)), fn (string $v): bool => $v !== ''));
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => self::msg('validation.upload_ini_size', 'File exceeds server upload limit.'),
            UPLOAD_ERR_FORM_SIZE  => self::msg('validation.upload_form_size', 'File exceeds form size limit.'),
            UPLOAD_ERR_PARTIAL    => self::msg('validation.upload_partial', 'File was only partially uploaded.'),
            UPLOAD_ERR_NO_FILE    => self::msg('validation.required_file', 'A file is required.'),
            UPLOAD_ERR_NO_TMP_DIR => 'Upload temporary folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            default               => 'Unknown upload error.',
        };
    }
}
