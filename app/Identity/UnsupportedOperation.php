<?php
declare(strict_types=1);

namespace App\Identity;

use RuntimeException;

/** Thrown by an IdentityVerificationProvider adapter for an operation it does not support. */
final class UnsupportedOperation extends RuntimeException
{
}
