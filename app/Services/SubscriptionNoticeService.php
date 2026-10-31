<?php

namespace App\Services;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The "your plan is ending" emails, and the one after it has.
 *
 *   two days before the end      ending_2
 *   the day before               ending_1
 *   the morning of the last day  ending_0
 *   once it has lapsed           ended
 *
 * Trials and paid terms alike: a trial that runs out and a renewal that was
 * never paid lock the account in exactly the same way, so they get the same
 * warning. Counted in whole calendar days (Subscription::daysLeft), so a
 * trial that ends at 3 pm on Friday is "today" on Friday morning, not "in
 * nine hours".
 *
 * ---------------------------------------------------------------------------
 * Said once, whatever runs it
 * ---------------------------------------------------------------------------
 *
 * Each notice is written to subscription_notices before it is sent, and only
 * sent if that insert landed. The unique index on (subscription, kind,
 * ends_on) is what makes a second run, a double-firing cron or a resumed
 * crash harmless - the same idea as the payment reminders. If the send itself
 * fails the row is taken back out, so tomorrow's run tries again.
 *
 * Missing a day is also harmless in the other direction: a run that did not
 * happen on the day before still sends "ends today" on the day.
 *
 * What it does not do is lock anybody out. That is EnsureTenantIsActive and
 * EnsureShopIsSubscribed, on every request, from the clock. This only tells
 * people it is about to happen, and that it has.
 */
class SubscriptionNoticeService
{
    public const ENDING_2 = 'ending_2';

    public const ENDING_1 = 'ending_1';

    public const ENDING_0 = 'ending_0';

    public const ENDED = 'ended';

    /** How long after the end an "ended" notice is still worth sending. */
    private const ENDED_WINDOW_DAYS = 7;

    /**
     * Send whatever is due right now.
     *
     * @return Collection<int, array{subscription: Subscription, kind: string, recipients: int}>
     */
    public function run(): Collection
    {
        $sent = collect();

        foreach ($this->candidates() as $subscription) {
            $kind = $this->kindFor($subscription);

            if ($kind === null) {
                continue;
            }

            $recipients = $this->deliver($subscription, $kind);

            if ($recipients !== null) {
                $sent->push(['subscription' => $subscription, 'kind' => $kind, 'recipients' => $recipients]);
            }
        }

        return $sent;
    }

    /**
     * Which notice, if any, this subscription is due today.
     */
    public function kindFor(Subscription $subscription): ?string
    {
        if ($subscription->ends_at === null || $subscription->cancelled_at !== null) {
            return null;
        }

        $tenant = $subscription->tenant;

        // Shut by a person, for a dispute or an investigation: "renew now"
        // is not the way back in, so it is not what we say.
        if ($tenant === null || ($tenant->isSuspended() && ! $tenant->isSuspendedForBilling())) {
            return null;
        }

        if ($subscription->ends_at->isFuture()) {
            return match ($subscription->daysLeft()) {
                2 => self::ENDING_2,
                1 => self::ENDING_1,
                0 => self::ENDING_0,
                default => null,
            };
        }

        $lockedSince = $subscription->graceEndsAt();

        return ! $subscription->isUsable()
            && $lockedSince !== null
            && $lockedSince->greaterThan(Carbon::now()->subDays(self::ENDED_WINDOW_DAYS))
            ? self::ENDED
            : null;
    }

    /**
     * Who hears about a business's plan: its owners.
     *
     * Falls back to the business's own email address - an account whose
     * owner has been deactivated still has somebody to tell, and the address
     * typed at sign-up is the one most likely to be read.
     *
     * @return Collection<int, array{email: string, name: string}>
     */
    public function recipientsFor(Subscription $subscription): Collection
    {
        $owners = User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('is_active', true)
            ->role(TenantProvisioningService::OWNER_ROLE)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['email' => $user->email, 'name' => $user->name]);

        if ($owners->isEmpty() && filled($subscription->tenant?->email)) {
            $owners->push(['email' => $subscription->tenant->email, 'name' => $subscription->tenant->name]);
        }

        return $owners->unique('email')->values();
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The subscription that speaks for each outlet and each business.
     *
     * The newest row per (tenant, shop): a business-wide row and a per-outlet
     * row are both live at once, and an old row left behind by a plan change
     * has an end date in the past by design - warning about that one would
     * tell a business that just upgraded that it is about to be cut off.
     *
     * Narrowed in SQL to the window anything could be due in, so the morning
     * run reads a handful of rows rather than every subscription ever sold.
     *
     * @return Collection<int, Subscription>
     */
    private function candidates(): Collection
    {
        $current = Subscription::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('tenant_id', 'shop_id')
            ->pluck('id');

        return Subscription::query()
            ->whereIn('id', $current)
            ->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [
                Carbon::now()->subDays(self::ENDED_WINDOW_DAYS + 31),
                Carbon::now()->addDays(3),
            ])
            ->with(['tenant', 'plan', 'shop'])
            ->orderBy('ends_at')
            ->get();
    }

    /**
     * Claim the notice, then send it. Null when it had already been sent.
     */
    private function deliver(Subscription $subscription, string $kind): ?int
    {
        $key = [
            'subscription_id' => $subscription->id,
            'kind' => $kind,
            'ends_on' => $subscription->ends_at->toDateString(),
        ];

        $claimed = DB::table('subscription_notices')->insertOrIgnore($key + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return null;
        }

        $recipients = $this->recipientsFor($subscription);

        try {
            foreach ($recipients as $to) {
                Mail::to($to['email'], $to['name'])
                    ->send(new SubscriptionNoticeMail($subscription, $kind, $to['name']));
            }
        } catch (\Throwable $e) {
            // Give the claim back, so the next run tries again rather than
            // the notice being marked sent when nobody received it.
            DB::table('subscription_notices')->where($key)->delete();

            Log::warning('Subscription notice failed to send', [
                'subscription_id' => $subscription->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        DB::table('subscription_notices')->where($key)->update([
            'recipients' => $recipients->count(),
            'sent_at' => now(),
            'updated_at' => now(),
        ]);

        return $recipients->count();
    }
}
