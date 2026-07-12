<?php
declare(strict_types=1);

namespace App\Entitlements;

use RuntimeException;

/** Thrown when a user is denied an entitlement (boolean off, or at/over a limit). */
final class EntitlementDenied extends RuntimeException
{
}
