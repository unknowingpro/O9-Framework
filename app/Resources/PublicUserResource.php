<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Resource;

/**
 * A user as seen by SOMEONE ELSE — a comment author, a leaderboard row, a
 * member list.
 *
 * Deliberately narrower than UserResource: no email, no locale, no timestamps.
 * The reason for a second class rather than a flag on the first is that the
 * decision "is this the viewer's own record?" then lives at the call site, where
 * the answer is actually known — instead of a `$public` boolean that someone
 * forgets to pass and silently leaks every user's email into a public list.
 *
 * App layer: sample. Projects own app/Resources/.
 */
final class PublicUserResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'    => (int) ($this->data['id'] ?? 0),
            'name'  => self::displayName($this->data),
            'roles' => self::publicRoles($this->data['roles'] ?? ''),
        ];
    }

    /**
     * No display-name column exists in the starter schema, so fall back to the
     * local part of the email — never the full address, which would leak the
     * very thing this resource exists to hide.
     *
     * @param array<string, mixed> $data
     */
    private static function displayName(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = (string) ($data['email'] ?? '');
        $at = strpos($email, '@');

        return $at === false ? '' : substr($email, 0, $at);
    }

    /**
     * Only expose roles that are meaningful to other users (a badge), not the
     * internal capability set.
     *
     * @return list<string>
     */
    private static function publicRoles(mixed $raw): array
    {
        $visible = ['admin', 'moderator', 'coach'];
        $parts = is_array($raw) ? array_map('strval', $raw) : array_map('trim', explode(',', (string) $raw));

        return array_values(array_intersect($visible, $parts));
    }
}
