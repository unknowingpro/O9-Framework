<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

/** Thrown by a PaymentGateway adapter for an operation it does not support. */
final class UnsupportedOperation extends RuntimeException
{
}
