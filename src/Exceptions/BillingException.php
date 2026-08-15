<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Exceptions;

use RuntimeException;

/**
 * Configuration-error exception — missing gateway, missing return_url, unsupported currency, etc.
 * One class for the whole package (static factories per case), not one exception per scenario.
 */
class BillingException extends RuntimeException
{
    public static function unknownGateway(string $name): self
    {
        return new self("Billing gateway \"{$name}\" is not registered.");
    }

    public static function missingReturnUrl(string $type): self
    {
        return new self("Billing return URL for \"{$type}\" is not configured (pass it via ChargeOptions or set config('billing.return_urls.{$type}')).");
    }

    public static function unsupportedCurrency(string $currency, string $gateway): self
    {
        return new self("Currency \"{$currency}\" is not supported by gateway \"{$gateway}\" and no CurrencyConverterContract is bound to convert it.");
    }
}
