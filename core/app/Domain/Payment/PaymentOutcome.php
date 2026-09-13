<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What a provider thinks a reference's state is, for `resolveStatus()`.
 *
 * A small enum rather than a string, because a reconciler branches on it and a typo in a string
 * would silently mean "unknown".
 */
enum PaymentOutcome: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Pending = 'pending';
    case Unknown = 'unknown';
}
