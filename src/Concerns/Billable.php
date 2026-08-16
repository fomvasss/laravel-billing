<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Concerns;

use Fomvasss\Billing\Models\Payment;
use Fomvasss\Billing\Models\PaymentMethod;
use Fomvasss\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Default implementation of Contracts\Billable plus the consumer-side accessors — pull the model's
 * payments/subscriptions/saved cards without going through the package models' forBillable()
 * scopes. tenantId() mirrors AiTask::tenantId() in laravel-ai-tasks: null (no tenant) by default,
 * override in the model that `use`s this trait if multi-tenancy is needed.
 */
trait Billable
{
    public function tenantId(): ?string
    {
        return null;
    }

    /** All payments where this model is the payer — chain the usual scopes: $user->payments()->paid(). */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'billable');
    }

    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    public function paymentMethods(): MorphMany
    {
        return $this->morphMany(PaymentMethod::class, 'billable');
    }

    /**
     * The saved card renewals charge — usable as a property ($user->defaultPaymentMethod). NB:
     * "default" is per gateway, so a billable with cards on several gateways has several — this
     * relation just returns one of them; use defaultPaymentMethodFor() when the gateway matters.
     */
    public function defaultPaymentMethod(): MorphOne
    {
        return $this->morphOne(PaymentMethod::class, 'billable')->where('is_default', true);
    }

    public function defaultPaymentMethodFor(string $gateway): ?PaymentMethod
    {
        return $this->paymentMethods()->where('gateway', $gateway)->where('is_default', true)->first();
    }

    /**
     * Same "entitled right now" definition as Subscription::isActive()/the active() scope —
     * trialing, active, or past_due still inside the dunning grace window. $planCode narrows to
     * one plan when the billable holds several concurrent subscriptions.
     */
    public function activeSubscription(?string $planCode = null): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->when($planCode !== null, fn ($query) => $query->whereHas('price.plan', fn ($q) => $q->where('code', $planCode)))
            ->latest()
            ->first();
    }

    /** The gate/middleware one-liner: $organization->hasActiveSubscription('pro'). */
    public function hasActiveSubscription(?string $planCode = null): bool
    {
        return $this->activeSubscription($planCode) !== null;
    }
}
