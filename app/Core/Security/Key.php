<?php
declare(strict_types=1);

namespace App\Core\Security;

/**
 * Key wrapper mirroring Firebase\JWT\Key so call sites read identically:
 *   Jwt::decode($token, new Key($secret, 'HS256'));
 */
final class Key
{
    public function __construct(
        public readonly string $keyMaterial,
        public readonly string $algorithm = 'HS256',
    ) {
    }
}
