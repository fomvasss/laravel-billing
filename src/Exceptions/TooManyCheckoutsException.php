<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * checkout_rate_limit exceeded. A BillingException, so existing catches still see it; an HTTP
 * exception, so an uncaught one renders as 429 with Retry-After instead of a 500.
 */
class TooManyCheckoutsException extends BillingException implements HttpExceptionInterface
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Too many checkout attempts. Try again in {$retryAfter} seconds.");
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    public function getHeaders(): array
    {
        return ['Retry-After' => (string) $this->retryAfter];
    }
}
