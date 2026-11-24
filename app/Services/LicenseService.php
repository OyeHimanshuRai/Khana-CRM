<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The license lifecycle, in one place.
 *
 * ---------------------------------------------------------------------------
 * Who may do what
 * ---------------------------------------------------------------------------
 *
 * A customer can do exactly one thing here: `activate()` - present a key the
 * platform gave them. Everything else (generate, assign, approve, reject,
 * switch off, block, revoke, reset, renew, extend, change plan) is the
 * Super Admin's, and the controllers that reach those methods are behind
 * `settings.licenses.edit`. The customer-facing controller never takes a
 * plan, an expiry or a status from the request.
 *
 * ---------------------------------------------------------------------------
 * Keys
 * ---------------------------------------------------------------------------
 *
 * `generate()` returns the plain key once. Only `hashKey()` of it is stored,
 * so a database dump holds nothing that can be typed into the form.
 *
 * Every state change is a transaction on a locked row and writes a
 * LicenseLog line (and an ActivityLog entry), so the history is the audit
 * trail and the two can never disagree about what happened.
 */
class LicenseService
{
    /** No 0/O/1/I/L: keys are read aloud and typed from a phone. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /* ------------------------------------------------------------- keys */

    /** Letters and digits only, upper-case: how a key is compared, however it was typed. */
    public static function normalise(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
    }

    /** Keyed hash: unreadable without the application key, and stable for lookup. */
    public static function hashKey(string $key): string
    {
        return hash_hmac('sha256', self::normalise($key), (string) config('app.key'));
    }

    /** TSW-2026-****-****-XYZ - recognisable in a list, useless to an attacker. */
    private static function hint(string $key): string
    {
        $parts = explode('-', $key);

        return implode('-', [$parts[0], $parts[1], '****', '****', $parts[4]]);
    }

    private function freshKey(): string
    {
        $chunk = function (int $length): string {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            return $out;
        };

        return implode('-', [(string) config('license.prefix', 'TSW'), now()->format('Y'), $chunk(4), $chunk(4), $chunk(3)]);
    }

    /* -------------------------------------------------------- generation */

    /**
     * Make a key and a row for it. The plain key is in the return value and
     * nowhere else - show it once and let it go.
     *
     * @param  array{plan_id: int, validity_days?: int|null, expires_at?: CarbonInterface|string|null, tenant_id?: int|null, notes?: string|null}  $data
     * @return array{0: License, 1: string}
     */
    public function generate(array $data, ?User $actor = null): array
    {
        $actor ??= Auth::user();
        $plan = Plan::query()->findOrFail($data['plan_id']);

        $expires = ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null;
        $days = (int) ($data['validity_days'] ?? 0);

        if ($expires === null && $days < 1) {
            throw new RuntimeException('Give the license a validity in days or an expiry date.');
        }

        if ($expires !== null && $expires->isPast()) {
            throw new RuntimeException('The expiry date has to be in the future.');
        }

        if (! empty($data['tenant_id'])) {
            Tenant::query()->findOrFail($data['tenant_id']);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = $this->freshKey();

            try {
                $license = DB::transaction(function () use ($key, $plan, $data, $expires, $days, $actor) {
                    $license = License::create([
                        'key_hash' => self::hashKey($key),
                        'key_hint' => self::hint($key),
                        'plan_id' => $plan->id,
                        'tenant_id' => $data['tenant_id'] ?? null,
                        'status' => License::ISSUED,
                        'validity_days' => $days > 0 ? $days : max(1, (int) now()->startOfDay()->diffInDays($expires)),
                        'expires_at' => $expires,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $actor?->id,
                        'updated_by' => $actor?->id,
                    ]);

                    $this->log($license, 'generated', sprintf(
                        'Generated %s on the %s plan%s',
                        $license->key_hint,
                        $plan->name,
                        $license->tenant_id ? ' for '.$license->tenant?->name : '',
                    ), ['plan_id' => $plan->id], $actor);

                    return $license;
                });

                return [$license, $key];
            } catch (QueryException $e) {
                // A collision on the unique hash: astronomically unlikely, and a new key fixes it.
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique license key. Please try again.');
    }

    /**
     * A customer has just registered: their license is created on the spot and
     * is waiting for the Super Admin, who sees it against the company.
     *
     * The key is generated and stored hashed like any other, and is not shown
     * to anybody: this license is approved, not typed in. If the platform
     * would rather the customer enter a key, "Reset" it and issue one from the
     * company's License panel.
     */
    public function issueAtRegistration(Tenant $tenant, Plan $plan, User $owner, ?Request $request = null): ?License
    {
        if (! $tenant->needsLicense()) {
            return null;
        }

        [$license] = $this->generate([
            'plan_id' => $plan->id,
            'validity_days' => (int) config('license.default_days', 365),
            'tenant_id' => $tenant->id,
            'notes' => 'Created at registration',
        ], $owner);

        DB::transaction(function () use ($license, $tenant, $owner, $request) {
            $license->forceFill([
                'holder_tenant_id' => $tenant->id,
                'activated_by_user_id' => $owner->id,
                'status' => License::PENDING,
                'requested_at' => now(),
            ])->save();

            LicenseActivation::create([
                'license_id' => $license->id,
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'status' => LicenseActivation::PENDING,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'requested_at' => now(),
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]);

            $this->log($license, 'registered', sprintf('%s registered; license created and waiting for approval', $tenant->name), [], $owner, $tenant, $request);
        });

        DB::afterCommit(fn () => app(EventNotifier::class)->licenseRequested($license->fresh(['tenant', 'plan'])));

        return $license->fresh();
    }

    /**
     * Issue a fresh key for a license nobody has entered yet; the plain key is
     * returned once. This is how the Super Admin hands a customer something to
     * type after a reset.
     *
     * @return array{0: License, 1: string}
     */
    public function rotateKey(License $license, ?User $actor = null): array
    {
        $actor ??= Auth::user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = $this->freshKey();

            try {
                $license = $this->locked($license, function (License $l) use ($key, $actor) {
                    if ($l->status !== License::ISSUED) {
                        throw new RuntimeException('A new key can only be issued while the license is not activated. Reset it first.');
                    }

                    $l->forceFill([
                        'key_hash' => self::hashKey($key),
                        'key_hint' => self::hint($key),
                        'updated_by' => $actor?->id,
                    ])->save();

                    $this->log($l, 'key_issued', 'A new key '.$l->key_hint.' was issued', [], $actor, $l->tenant);

                    return $l;
                });

                return [$license, $key];
            } catch (QueryException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique license key. Please try again.');
    }

    /* --------------------------------------------------------- customer */

    /**
     * A customer presents a key, once.
     *
     * Every refusal is a RuntimeException written for the person at the form.
     * "No such key" and "that key is for somebody else" read the same on
     * purpose: telling a guesser which keys exist is the whole of enumeration.
     */
    public function activate(User $user, Tenant $tenant, string $plainKey, ?Request $request = null): License
    {
        $invalid = new RuntimeException('That license key is not valid for this account. Check it and try again.');

        $hash = self::hashKey($plainKey);

        try {
            return DB::transaction(function () use ($user, $tenant, $hash, $request, $invalid) {
                /** @var License|null $license */
                $license = License::query()->where('key_hash', $hash)->lockForUpdate()->first();

                if ($license === null) {
                    $this->log(null, 'activation_failed', 'An unknown license key was entered', [], $user, $tenant, $request);

                    throw $invalid;
                }

                if ($license->tenant_id !== null && (int) $license->tenant_id !== (int) $tenant->id) {
                    $this->log($license, 'activation_failed', 'A customer other than the assigned one tried this key', [
                        'tried_by_tenant' => $tenant->id,
                    ], $user, $tenant, $request);

                    throw $invalid;
                }

                if ($license->status === License::REVOKED) {
                    throw new RuntimeException('This license key has been revoked. Please contact support.');
                }

                if ($license->status === License::BLOCKED) {
                    throw new RuntimeException('This license key has been blocked. Please contact support.');
                }

                if ($license->status !== License::ISSUED) {
                    throw new RuntimeException('This license key has already been registered.');
                }

                if (License::query()->where('holder_tenant_id', $tenant->id)->exists()) {
                    throw new RuntimeException('Your account already has a license registered.');
                }

                $license->forceFill([
                    'tenant_id' => $tenant->id,
                    'holder_tenant_id' => $tenant->id,
                    'activated_by_user_id' => $user->id,
                    'status' => License::PENDING,
                    'requested_at' => now(),
                    'updated_by' => $user->id,
                ])->save();

                LicenseActivation::create([
                    'license_id' => $license->id,
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'status' => LicenseActivation::PENDING,
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                    'requested_at' => now(),
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $this->log($license, 'activation_requested', sprintf(
                    '%s entered %s; waiting for approval',
                    $tenant->name,
                    $license->key_hint,
                ), [], $user, $tenant, $request);

                DB::afterCommit(fn () => app(EventNotifier::class)->licenseRequested($license->fresh(['tenant', 'plan'])));

                return $license;
            });
        } catch (QueryException $e) {
            // Two requests for the same customer raced; the unique lock let one through.
            throw new RuntimeException('Your account already has a license registered.');
        }
    }

    /* ----------------------------------------------------- super admin */

    /** Hand an unused key to one customer; only they will be able to enter it. */
    public function assign(License $license, ?Tenant $tenant, ?User $actor = null): License
    {
        return $this->locked($license, function (License $l) use ($tenant, $actor) {
            if ($l->status !== License::ISSUED) {
                throw new RuntimeException('Only a key nobody has entered yet can be assigned.');
            }

            $l->forceFill(['tenant_id' => $tenant?->id, 'updated_by' => $actor?->id])->save();

            $this->log($l, 'assigned', $tenant ? 'Assigned to '.$tenant->name : 'Assignment removed', [], $actor, $tenant);

            return $l;
        });
    }

    /** Approve a customer's activation: the license goes live and the plan is applied. */
    public function approve(License $license, ?User $actor = null): License
    {
        $actor ??= Auth::user();

        return $this->locked($license, function (License $l) use ($actor) {
            if ($l->status !== License::PENDING) {
                throw new RuntimeException('Only a license waiting for approval can be approved.');
            }

            if ($l->plan === null || $l->tenant === null) {
                throw new RuntimeException('This license has no plan or customer to apply.');
            }

            $expires = $l->expires_at ?? now()->addDays($l->validity_days)->endOfDay();

            if ($expires->isPast()) {
                throw new RuntimeException('The license expiry date has already passed. Extend it first.');
            }

            $l->forceFill([
                'status' => License::ACTIVE,
                'activated_at' => now(),
                'approved_by' => $actor?->id,
                'expires_at' => $expires,
                'updated_by' => $actor?->id,
            ])->save();

            LicenseActivation::query()->where('license_id', $l->id)->where('status', LicenseActivation::PENDING)->update([
                'status' => LicenseActivation::APPROVED,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->subscriptions->grantTerm($l->tenant, $l->plan, $expires, 'License '.$l->key_hint);

            $this->log($l, 'approved', sprintf('Approved for %s until %s', $l->tenant->name, $expires->format('j M Y')), [], $actor, $l->tenant);

            return $l;
        });
    }

    public function reject(License $license, ?string $reason = null, ?User $actor = null): License
    {
        $actor ??= Auth::user();

        return $this->locked($license, function (License $l) use ($reason, $actor) {
            if ($l->status !== License::PENDING) {
                throw new RuntimeException('Only a license waiting for approval can be rejected.');
            }

            $l->forceFill(['status' => License::ISSUED, 'holder_tenant_id' => null, 'updated_by' => $actor?->id])->save();

            LicenseActivation::query()->where('license_id', $l->id)->where('status', LicenseActivation::PENDING)->update([
                'status' => LicenseActivation::REJECTED,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'reason' => $reason,
                'updated_by' => $actor?->id,
            ]);

            $this->log($l, 'rejected', 'Activation rejected'.($reason ? ': '.$reason : ''), ['reason' => $reason], $actor, $l->tenant);

            return $l;
        });
    }

    /** Switch a working license off without withdrawing it. */
    public function deactivate(License $license, ?string $reason = null, ?User $actor = null): License
    {
        return $this->transition($license, [License::ACTIVE], License::INACTIVE, 'deactivated', 'Deactivated', $reason, $actor);
    }

    public function reactivate(License $license, ?User $actor = null): License
    {
        return $this->transition($license, [License::INACTIVE], License::ACTIVE, 'reactivated', 'Activated again', null, $actor);
    }

    public function block(License $license, ?string $reason = null, ?User $actor = null): License
    {
        return $this->transition(
            $license,
            [License::ISSUED, License::PENDING, License::ACTIVE, License::INACTIVE],
            License::BLOCKED,
            'blocked',
            'Blocked',
            $reason,
            $actor,
            ['blocked_at' => now()],
        );
    }

    public function unblock(License $license, ?User $actor = null): License
    {
        return $this->locked($license, function (License $l) use ($actor) {
            if ($l->status !== License::BLOCKED) {
                throw new RuntimeException('This license is not blocked.');
            }

            $back = match (true) {
                $l->activated_at !== null => License::ACTIVE,
                $l->holder_tenant_id !== null => License::PENDING,
                default => License::ISSUED,
            };

            $l->forceFill(['status' => $back, 'blocked_at' => null, 'updated_by' => $actor?->id ?? Auth::id()])->save();

            $this->log($l, 'unblocked', 'Unblocked; back to '.(License::STATUSES[$back]['label']), [], $actor, $l->tenant);

            return $l;
        });
    }

    /** Withdraw a license for good. The key can never be used again. */
    public function revoke(License $license, ?string $reason = null, ?User $actor = null): License
    {
        $actor ??= Auth::user();

        return $this->locked($license, function (License $l) use ($reason, $actor) {
            if ($l->status === License::REVOKED) {
                throw new RuntimeException('This license is already revoked.');
            }

            $l->forceFill([
                'status' => License::REVOKED,
                'holder_tenant_id' => null,
                'revoked_at' => now(),
                'updated_by' => $actor?->id,
            ])->save();

            LicenseActivation::query()->where('license_id', $l->id)->where('status', LicenseActivation::PENDING)->update([
                'status' => LicenseActivation::REJECTED,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'reason' => 'License revoked',
                'updated_by' => $actor?->id,
            ]);

            $this->log($l, 'revoked', 'Revoked'.($reason ? ': '.$reason : ''), ['reason' => $reason], $actor, $l->tenant);

            return $l;
        });
    }

    /**
     * Wipe the registration so the customer has to enter a key again.
     *
     * The key goes back to "not activated" and stays with the same customer;
     * their subscription is left alone, because the license is what is
     * missing, not the term they were granted.
     */
    public function reset(License $license, ?string $reason = null, ?User $actor = null): License
    {
        $actor ??= Auth::user();

        return $this->locked($license, function (License $l) use ($reason, $actor) {
            if ($l->status === License::REVOKED) {
                throw new RuntimeException('A revoked license cannot be reset. Generate a new one.');
            }

            $l->forceFill([
                'status' => License::ISSUED,
                'holder_tenant_id' => null,
                'activated_at' => null,
                'approved_by' => null,
                'requested_at' => null,
                'blocked_at' => null,
                'updated_by' => $actor?->id,
            ])->save();

            LicenseActivation::query()->where('license_id', $l->id)->whereIn('status', [LicenseActivation::PENDING, LicenseActivation::APPROVED])->update([
                'status' => LicenseActivation::RESET,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'reason' => $reason,
                'updated_by' => $actor?->id,
            ]);

            $this->log($l, 'reset', 'Activation reset'.($reason ? ': '.$reason : ''), ['reason' => $reason], $actor, $l->tenant);

            return $l;
        });
    }

    /* ----------------------------------------------------------- expiry */

    /** Add the license's own validity (or a given number of days) from today or its current end, whichever is later. */
    public function renew(License $license, ?int $days = null, ?User $actor = null): License
    {
        return $this->moveExpiry($license, $days ?: $license->validity_days, 'renewed', $actor, true);
    }

    public function extend(License $license, int $days, ?User $actor = null): License
    {
        return $this->moveExpiry($license, $days, 'extended', $actor, false);
    }

    /** Set the expiry to an exact date (a shorter one is allowed: this is "change validity"). */
    public function setExpiry(License $license, CarbonInterface|string $date, ?User $actor = null): License
    {
        $date = Carbon::parse($date)->endOfDay();

        return $this->locked($license, function (License $l) use ($date, $actor) {
            if ($l->status === License::REVOKED) {
                throw new RuntimeException('A revoked license has no validity to change.');
            }

            $old = $l->expires_at;
            $l->forceFill(['expires_at' => $date, 'updated_by' => $actor?->id ?? Auth::id()])->save();

            $this->syncSubscription($l);

            $this->log($l, 'expiry_changed', sprintf(
                'Expiry changed from %s to %s',
                $old?->format('j M Y') ?? 'none',
                $date->format('j M Y'),
            ), ['from' => $old?->toDateString(), 'to' => $date->toDateString()], $actor, $l->tenant);

            return $l;
        });
    }

    private function moveExpiry(License $license, int $days, string $action, ?User $actor, bool $renewal): License
    {
        if ($days < 1 || $days > 3650) {
            throw new RuntimeException('Choose between 1 and 3650 days.');
        }

        return $this->locked($license, function (License $l) use ($days, $action, $actor, $renewal) {
            if (! in_array($l->status, [License::ACTIVE, License::INACTIVE, License::ISSUED], true)) {
                throw new RuntimeException('Only an issued, active or suspended license can have its expiry moved.');
            }

            $base = $l->expires_at !== null && $l->expires_at->isFuture() ? $l->expires_at->copy() : now();
            $new = $base->addDays($days)->endOfDay();
            $old = $l->expires_at;

            $l->forceFill([
                'expires_at' => $new,
                'last_renewed_at' => $renewal ? now() : $l->last_renewed_at,
                'updated_by' => $actor?->id ?? Auth::id(),
            ])->save();

            $this->syncSubscription($l);

            $this->log($l, $action, sprintf('%s by %d days; now runs until %s', ucfirst($action), $days, $new->format('j M Y')), [
                'days' => $days, 'from' => $old?->toDateString(), 'to' => $new->toDateString(),
            ], $actor, $l->tenant);

            return $l;
        });
    }

    /** Upgrade or downgrade: same term, different plan - for the license and the subscription. */
    public function changePlan(License $license, Plan $plan, ?User $actor = null): License
    {
        return $this->locked($license, function (License $l) use ($plan, $actor) {
            if ($l->status === License::REVOKED) {
                throw new RuntimeException('A revoked license cannot change plan.');
            }

            if ((int) $l->plan_id === (int) $plan->id) {
                throw new RuntimeException('That is already the plan on this license.');
            }

            $old = $l->plan;
            $direction = $old === null ? 'changed' : ((float) $plan->monthly_price >= (float) $old->monthly_price ? 'upgraded' : 'downgraded');

            $l->forceFill(['plan_id' => $plan->id, 'updated_by' => $actor?->id ?? Auth::id()])->save();

            if ($l->status === License::ACTIVE && $l->tenant !== null && $l->expires_at !== null) {
                $this->subscriptions->grantTerm($l->tenant, $plan, $l->expires_at, 'License '.$l->key_hint.' '.$direction);
            }

            $this->log($l, 'plan_'.$direction, sprintf('Plan %s from %s to %s', $direction, $old?->name ?? 'none', $plan->name), [
                'from' => $old?->id, 'to' => $plan->id,
            ], $actor, $l->tenant);

            return $l;
        });
    }

    /* ---------------------------------------------------- customer block */

    /** Shut a whole customer out (a person's decision, not a lapse). */
    public function blockCustomer(Tenant $tenant, string $reason, ?User $actor = null): void
    {
        $this->subscriptions->suspend($tenant, $reason);

        $this->log($tenant->heldLicense, 'customer_blocked', sprintf('%s blocked: %s', $tenant->name, $reason), ['reason' => $reason], $actor, $tenant);
    }

    public function unblockCustomer(Tenant $tenant, ?User $actor = null): void
    {
        $this->subscriptions->reactivate($tenant);

        $this->log($tenant->heldLicense, 'customer_unblocked', $tenant->name.' unblocked', [], $actor, $tenant);
    }

    /**
     * A payment moved the subscription's end past the license's: bring the
     * license along, so it never expires on a customer who has paid.
     */
    public function syncExpiryFromSubscription(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $license = License::query()->where('holder_tenant_id', $tenantId)->where('status', License::ACTIVE)->first();
        $sub = Tenant::query()->find($tenantId)?->subscription()->first();

        if ($license === null || $sub?->ends_at === null) {
            return;
        }

        if ($license->expires_at === null || $sub->ends_at->gt($license->expires_at)) {
            $license->forceFill(['expires_at' => $sub->ends_at])->save();

            $this->log($license, 'expiry_synced', 'Expiry moved to '.$sub->ends_at->format('j M Y').' by a subscription payment', [], null, $license->tenant);
        }
    }

    /* ----------------------------------------------------------- guts */

    /** Bring the customer's subscription to the license's expiry (and plan). */
    private function syncSubscription(License $license): void
    {
        if ($license->status !== License::ACTIVE || $license->tenant === null || $license->expires_at === null) {
            return;
        }

        $sub = $license->tenant->subscription()->first();

        if ($sub === null || (int) $sub->plan_id !== (int) $license->plan_id) {
            if ($license->plan !== null) {
                $this->subscriptions->grantTerm($license->tenant, $license->plan, $license->expires_at, 'License '.$license->key_hint);
            }

            return;
        }

        $this->subscriptions->setEnd($sub, $license->expires_at, 'License '.$license->key_hint);
    }

    /**
     * @param  array<int, string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(License $license, array $from, string $to, string $action, string $label, ?string $reason, ?User $actor, array $extra = []): License
    {
        $actor ??= Auth::user();

        return $this->locked($license, function (License $l) use ($from, $to, $action, $label, $reason, $actor, $extra) {
            if (! in_array($l->status, $from, true)) {
                throw new RuntimeException(sprintf('A license that is "%s" cannot be set to "%s".', License::STATUSES[$l->status]['label'] ?? $l->status, License::STATUSES[$to]['label']));
            }

            $l->forceFill(['status' => $to, 'updated_by' => $actor?->id] + $extra)->save();

            $this->log($l, $action, $label.($reason ? ': '.$reason : ''), ['reason' => $reason], $actor, $l->tenant);

            return $l;
        });
    }

    /** Run a change on the license row under a lock, so two admins cannot step on each other. */
    private function locked(License $license, callable $change): License
    {
        return DB::transaction(function () use ($license, $change) {
            $fresh = License::query()->with(['tenant', 'plan'])->lockForUpdate()->findOrFail($license->id);

            return $change($fresh);
        });
    }

    /** One audit line, in the license's own history and in the platform's activity log. */
    private function log(?License $license, string $action, string $description, array $meta = [], ?User $actor = null, ?Tenant $tenant = null, ?Request $request = null): void
    {
        $actor ??= Auth::user();

        LicenseLog::create([
            'license_id' => $license?->id,
            'tenant_id' => $tenant?->id ?? $license?->tenant_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'description' => Str::limit($description, 250, ''),
            'meta' => $meta ?: null,
            'ip_address' => ($request ?? request())?->ip(),
        ]);

        ActivityLog::record('license.'.$action, $description, $license);
    }
}
