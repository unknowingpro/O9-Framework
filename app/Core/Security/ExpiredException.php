<?php
declare(strict_types=1);

namespace App\Core\Security;

/** Thrown by Jwt::decodeStrict() when the token's `exp` claim is in the past. */
final class ExpiredException extends \UnexpectedValueException
{
}
