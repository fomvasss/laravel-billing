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
    | How many charge attempts a renewal gets in total — the first one plus the
    | retries — before the subscription is marked canceled. Counts attempts,
    | not retries: 4 means the renewal charge itself and three retries, which
    | is what the default retry_intervals below pace out.
    |
    */

    'max_recurring_attempts' => env('BILLING_MAX_RECURRING_ATTEMPTS', 4),

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | How long access outlives a failed renewal, on top of the wait until the
    | next retry: grace_ends_at is stamped at next_retry_at + this many days, so
    | the window always covers the gap to the next attempt no matter how long
    | the retry_intervals below get. Only meaningful while grace_access is on.
    |
    */

    'grace_period_days' => env('BILLING_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Retry intervals
    |--------------------------------------------------------------------------
    |
    | How long to wait after each failed recurring charge before trying again —
    | a list, because a card that just failed is worth retrying soon, while the
    | third failure in a row is worth waiting a day or two on. Same entry shape
    | as trial_ending_notices: a CarbonInterval string or an int = minutes.
    |
    | The first entry paces the wait after the first failure, the second after
    | the second, and so on; a list shorter than max_recurring_attempts repeats
    | its last entry. The default pairs with max_recurring_attempts = 4: charge,
    | +6h, +24h, +48h, then canceled. An empty list means no retries at all —
    | the first failed renewal cancels the subscription outright.
    |
    | Overridable per Price via prices.retry_intervals (null = this list).
    |
    */

    'retry_intervals' => ['6 hours', '24 hours', '48 hours'],

    /*
    |--------------------------------------------------------------------------
    | Grace access
    |--------------------------------------------------------------------------
    |
    | Whether isActive() stays true for a past_due subscription while retries
    | are still running (true, the default) or turns false the moment a
    | renewal first fails, before the retries have even had a chance to
    | recover it (false). Either way recurring_attempts/grace_ends_at and the
    | retry cycle itself are unaffected — this only gates customer-facing
    | access. Override per Price via prices.grace_access (null = this value).
    |
    */

    'grace_access' => env('BILLING_GRACE_ACCESS', true),

    /*
    |--------------------------------------------------------------------------
    | Trial ending notices
    |--------------------------------------------------------------------------
    |
    | When TrialWillEnd fires before trial_ends_at — the hook for "add a card
    | before your trial runs out" notifications. A list, because the sensible
    | cadence depends on the period: a yearly plan wants ['7 days', '3 days',
    | '1 day'], an hourly rental wants ['1 hour', '15 minutes']. Each entry is
    | a CarbonInterval string (or an int = minutes) and fires at most once per
    | subscription; the event carries which notice it is ($event->notice).
    | If several become due at once (e.g. the trial was created mid-window),
    | only the closest one fires — no notification burst.
    |
    */

    'trial_ending_notices' => ['3 days'],

    /*
    |--------------------------------------------------------------------------
    | Renewal charge options
    |--------------------------------------------------------------------------
    |
    | A scheduled renewal has no request and no caller behind it, so the fiscal
    | basket an ordinary charge picks up from a HasReceiptItems payable can't
    | reach it. Turn receipt_items on and every renewal carries a one-line
    | basket — the payment's full amount, named after the plan (or after the
    | price's meta.receipt_name) — which is what Monobank's basketOrder,
    | WayForPay's products and Hutko's RRO reservation_data need.
    |
    | Anything richer (per-seat lines, tax codes, LiqPay's rro_info) comes from
    | your own Contracts\RenewalChargeOptionsContract binding, which also gets
    | to set the description, customer email and IP for the same charge.
    |
    */

    'renewal' => [
        'receipt_items' => env('BILLING_RENEWAL_RECEIPT_ITEMS', false),
    ],

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
    | Any gateway block also takes an optional 'currencies' => ['UAH', ...]
    | override: the driver's built-in list is an approximation (no gateway has
    | a "list my currencies" API, and availability depends on your merchant
    | account) — narrow or extend it here without touching the driver.
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
            'link_ttl_minutes' => env('LIQPAY_LINK_TTL_MINUTES', 60),
        ],

        'wayforpay' => [
            'merchant_account' => env('WAYFORPAY_MERCHANT_ACCOUNT'),
            'merchant_domain' => env('WAYFORPAY_MERCHANT_DOMAIN'),
            'secret_key' => env('WAYFORPAY_SECRET_KEY'),
            'link_ttl_minutes' => env('WAYFORPAY_LINK_TTL_MINUTES', 1440),
        ],

        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'hutko' => [
            'merchant_id' => env('HUTKO_MERCHANT_ID'),
            'secret_key' => env('HUTKO_SECRET_KEY'),
            'link_ttl_minutes' => env('HUTKO_LINK_TTL_MINUTES', 1440),
        ],

    ],

];
