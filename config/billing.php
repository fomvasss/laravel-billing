<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Debug logging
    |--------------------------------------------------------------------------
    |
    | When enabled, AbstractGateway::log() writes driver-level debug entries
    | (via the default log channel). Each driver decides what it logs — never
    | dump a raw webhook payload here if it may contain tokens/PII. Dev/staging
    | only, not a production audit trail.
    |
    */

    'debug' => env('BILLING_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Return URLs
    |--------------------------------------------------------------------------
    |
    | Fallback success/fail redirect URLs, used by AbstractGateway::successUrl()/
    | failUrl() when a charge's ChargeOptions doesn't override them. Leaving
    | both null and never passing an override throws BillingException on charge.
    |
    */

    'return_urls' => [
        'success' => env('BILLING_RETURN_URL_SUCCESS'),
        'failed' => env('BILLING_RETURN_URL_FAILED'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring charge attempts
    |--------------------------------------------------------------------------
    |
    | How many failed recurring-charge retries a subscription gets (status
    | past_due, grace_ends_at) before it's marked canceled.
    |
    */

    'max_recurring_attempts' => env('BILLING_MAX_RECURRING_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Gateway credentials
    |--------------------------------------------------------------------------
    |
    | Read by the default CredentialResolverContract binding (Support\DefaultCredentialResolver)
    | — config('billing.gateways.{$gateway}'), tenant-agnostic. Bind your own
    | CredentialResolverContract implementation for dynamic per-tenant credentials.
    |
    */

    'gateways' => [

    ],

];
