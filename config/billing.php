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
    | Grace period
    |--------------------------------------------------------------------------
    |
    | Days a subscription stays `past_due` (still counted as paying, still
    | retried) after a failed recurring charge before recurring_attempts hits
    | max_recurring_attempts and it's marked canceled.
    |
    */

    'grace_period_days' => env('BILLING_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | How long a Payment can sit `pending` before billing:reconcile-pending-payments
    | polls the gateway for it (or marks it canceled, for gateways with no
    | status-polling endpoint) — a fallback for a webhook that never arrived.
    |
    */

    'reconcile_after_minutes' => env('BILLING_RECONCILE_AFTER_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Scheduled commands
    |--------------------------------------------------------------------------
    |
    | Off by default — unlike laravel-visits' equivalent flag, billing's
    | scheduled commands touch money (recurring charges) and reconciliation,
    | so registering them silently on install is worse than a host having to
    | opt in explicitly.
    |
    */

    'schedule' => [
        'enabled' => env('BILLING_SCHEDULE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook route
    |--------------------------------------------------------------------------
    |
    | One route handles every gateway (BillingServiceProvider registers it
    | once) — `{gateway}` must stay in the path, WebhookController resolves
    | the driver/signature validator/responder from that segment. Override
    | `path` to match your own convention (e.g. 'webhook/billing/{gateway}');
    | `middleware` is empty by default — webhook endpoints deliberately skip
    | the `web` group (no CSRF, no session), add your own (IP allowlist, rate
    | limiting, logging) here if needed.
    |
    */

    'webhook' => [
        'path' => env('BILLING_WEBHOOK_PATH', 'billing/webhooks/{gateway}'),
        'middleware' => [],
    ],

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
