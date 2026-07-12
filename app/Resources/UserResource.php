<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Resource;

/**
 * The authenticated user's own representation — what /api/v1/me returns.
 *
 * Note what is NOT here: `password_hash`, and `force_logout_at`. A resource is
 * an allow-list, so a column added to the users table later is invisible to the
 * API until someone deliberately adds it below. That is the entire point — the
 * alternative (returning the row and unset()-ing secrets) leaks every new
 * sensitive column by default.
 *
 * App layer: this is a sample. Projects own app/Resources/ and edit it freely —
 * sync-framework.php never touches it.
 */
final class UserResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => (int) ($this->data['id'] ?? 0),
            'email'        => (string) ($this->data['email'] ?? ''),
            'roles'        => self::roles($this->data['roles'] ?? ''),
            'locale'       => $this->data['locale'] ?? null,
            'last_seen_at' => $this->data['last_seen_at'] ?? null,
            'created_at'   => $this->data['created_at'] ?? null,
        ];
    }

    /**
     * `roles` is stored comma-separated ("admin,member") — the API hands clients
     * a real array rather than making every consumer re-parse the string.
     *
     * @return list<string>
     */
    private static function roles(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }

        $parts = array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($parts, static fn (string $r): bool => $r !== ''));
    }
}
