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
    | The final success/fail pages the customer lands on after checkout — your
    | own routes or a frontend/SPA URL on another origin. The gateway itself is
    | pointed at the package's return route (GET+POST, so WayForPay/Hutko's
    | POST-style returns work without CSRF exceptions on your side), which
    | fires the CheckoutReturned event and 303-redirects here with ?payment={id}
    | appended. A per-charge ChargeOptions successUrl/failUrl bypasses all of
    | that and goes to the gateway as-is. Leaving these null and never passing
    | an override throws BillingException on charge.
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
    | Retry interval
    |--------------------------------------------------------------------------
    |
    | Hours between recurring-charge retries after a failure. Spaces the
    | max_recurring_attempts across the grace window (default: 3 attempts,
    | 24h apart, 3-day grace) instead of burning them on consecutive
    | scheduler runs.
    |
    */

    'retry_interval_hours' => env('BILLING_RETRY_INTERVAL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Trial ending notice
    |--------------------------------------------------------------------------
    |
    | How many days before trial_ends_at the TrialWillEnd event fires (once per
    | subscription, from billing:expire-trials) — the hook for "add a card
    | before your trial runs out" notifications.
    |
    */

    'trial_ending_notice_days' => env('BILLING_TRIAL_ENDING_NOTICE_DAYS', 3),

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
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection/queue for the package's queued work (ProcessWebhookJob —
    | currently the only queued job). Both null by default = the app's own
    | defaults. Set a dedicated queue (e.g. 'billing') to give payment
    | webhooks their own worker/priority, so a busy default queue can't delay
    | marking payments paid.
    |
    */

    'queue' => [
        'connection' => env('BILLING_QUEUE_CONNECTION'),
        'queue' => env('BILLING_QUEUE'),
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
    | limiting, logging) here if needed. `prune_after_days` — how long stored
    | webhook calls are kept before `model:prune` (scheduled daily when
    | billing.schedule.enabled) deletes them; pruned rows also drop their
    | dedup claims, so keep it beyond any gateway's retry horizon (WayForPay
    | retries for up to 4 days).
    |
    */

    'webhook' => [
        'path' => env('BILLING_WEBHOOK_PATH', 'billing/webhooks/{gateway}'),
        'middleware' => [],
        'prune_after_days' => env('BILLING_WEBHOOK_PRUNE_AFTER_DAYS', 30),
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
    | Every built-in gateway is stubbed below; leave the ones you don't use
    | alone — an unset env just means that gateway stays unconfigured, and it
    | only ever fails when something actually tries to charge through it. Each
    | driver also declares this same field list at runtime via its static
    | credentialFields(), which is what an admin settings UI should read.
    |
    */

    'gateways' => [

        'monobank' => [
            'token' => env('MONOBANK_TOKEN'),
            'link_ttl_minutes' => env('MONOBANK_LINK_TTL_MINUTES', 60),
        ],

        'liqpay' => [
            'public_key' => env('LIQPAY_PUBLIC_KEY'),
            'private_key' => env('LIQPAY_PRIVATE_KEY'),
        ],

        'wayforpay' => [
            'merchant_account' => env('WAYFORPAY_MERCHANT_ACCOUNT'),
            'merchant_domain' => env('WAYFORPAY_MERCHANT_DOMAIN'),
            'secret_key' => env('WAYFORPAY_SECRET_KEY'),
        ],

        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'hutko' => [
            'merchant_id' => env('HUTKO_MERCHANT_ID'),
            'secret_key' => env('HUTKO_SECRET_KEY'),
        ],

    ],

];
