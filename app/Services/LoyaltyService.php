<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Points a regular earns and spends (SRS 15, 21).
 *
 * ---------------------------------------------------------------------------
 * The balance is the sum of the rows
 * ---------------------------------------------------------------------------
 *
 * Never a column, never a cached figure. See the migration for why. The
 * practical consequence is that nothing in this class can leave a balance
 * that disagrees with its own statement - the worst outcome available is a
 * missing row, which is visible, rather than a wrong number, which is not.
 *
 * ---------------------------------------------------------------------------
 * Earning is never allowed to fail a sale
 * ---------------------------------------------------------------------------
 *
 * `award()` is called after a bill is settled. The money is already taken. So
 * a programme that is switched off, a customer who is not on it, a bill below
 * the minimum - all of them return null rather than throwing, and the caller
 * carries on.
 *
 * Redeeming is the opposite: it happens *before* the total is struck, and a
 * redemption that silently did nothing would charge the guest full price for
 * points they thought they had spent. So `redeem()` throws.
 */
class LoyaltyService
{
    /** The programme for the branch in context, or null. */
    public function program(?int $shopId = null): ?LoyaltyProgram
    {
        $shopId ??= CurrentShop::id() ?? CurrentShop::idForWrite();

        if ($shopId === null) {
            return null;
        }

        return LoyaltyProgram::query()->where('shop_id', $shopId)->first();
    }

    public function isRunning(?int $shopId = null): bool
    {
        return (bool) $this->program($shopId)?->is_active;
    }

    /**
     * What a customer has, right now.
     *
     * A sum, every time it is asked. On a customer with a decade of history
     * that is still one indexed aggregate, and the alternative - a column
     * that drifts - costs more on the day it is wrong than this ever does.
     */
    public function balance(Customer $customer): int
    {
        return (int) LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->sum('points');
    }

    /**
     * Give points for a bill.
     *
     * Returns null when nothing was earned, which is an ordinary outcome and
     * not a failure - see the class comment.
     */
    public function award(Customer $customer, float $amount, ?Model $source = null, ?string $note = null): ?LoyaltyTransaction
    {
        $program = $this->program($customer->shop_id);

        if ($program === null || ! $program->is_active) {
            return null;
        }

        $points = $program->pointsFor($amount);

        if ($points <= 0) {
            return null;
        }

        /*
         | Once per bill, whatever happens upstream.
         |
         | A settle that is retried, a webhook that arrives twice, somebody
         | double-clicking - all real, and all of them would otherwise pay
         | out twice for one meal.
         */
        if ($source !== null && $this->alreadyAwarded($customer, $source)) {
            return null;
        }

        return $this->write(
            customer: $customer,
            type: LoyaltyTransaction::EARN,
            points: $points,
            source: $source,
            amount: $amount,
            expiresAt: $program->expiry_months !== null
                ? now()->addMonths($program->expiry_months)
                : null,
            note: $note,
        );
    }

    /**
     * Spend points against a bill.
     *
     * @throws RuntimeException with a message for whoever is at the till
     */
    public function redeem(Customer $customer, int $points, float $billTotal, ?Model $source = null): LoyaltyTransaction
    {
        $program = $this->program($customer->shop_id);

        if ($program === null || ! $program->is_active) {
            throw new RuntimeException('This branch does not run a loyalty programme.');
        }

        if ($points <= 0) {
            throw new RuntimeException('Enter how many points to use.');
        }

        $balance = $this->balance($customer);

        if ($points > $balance) {
            throw new RuntimeException(sprintf(
                '%s has %s point%s, which is not enough for %s.',
                $customer->name,
                number_format($balance),
                $balance === 1 ? '' : 's',
                number_format($points),
            ));
        }

        if ($balance < $program->min_redeem_points) {
            throw new RuntimeException(sprintf(
                'Points can be used from %s upwards. %s has %s.',
                number_format($program->min_redeem_points),
                $customer->name,
                number_format($balance),
            ));
        }

        $ceiling = $program->maxPointsFor($billTotal, $balance);

        if ($points > $ceiling) {
            /*
             | Named in points and in money, because the person at the till is
             | about to explain it to somebody across the counter.
             */
            throw new RuntimeException(sprintf(
                'Points can cover at most %d%% of a bill — that is %s points (%s) on this one.',
                $program->max_redeem_percent,
                number_format($ceiling),
                number_format($program->valueOf($ceiling), 2),
            ));
        }

        return $this->write(
            customer: $customer,
            type: LoyaltyTransaction::REDEEM,
            points: -$points,
            source: $source,
            amount: $program->valueOf($points),
        );
    }

    /**
     * Put points on or take them off by hand.
     *
     * A goodwill gesture, or the correction for a mistake. Always carries a
     * note, because an adjustment nobody explained is the one line on a
     * statement that cannot be defended later.
     */
    public function adjust(Customer $customer, int $points, string $note): LoyaltyTransaction
    {
        if ($points === 0) {
            throw new RuntimeException('An adjustment of zero points changes nothing.');
        }

        if (trim($note) === '') {
            throw new RuntimeException('Say why. An adjustment without a reason cannot be explained later.');
        }

        $balance = $this->balance($customer);

        if ($points < 0 && abs($points) > $balance) {
            throw new RuntimeException(sprintf(
                'That would take the balance below zero — %s has %s.',
                $customer->name,
                number_format($balance),
            ));
        }

        return $this->write($customer, LoyaltyTransaction::ADJUST, $points, note: $note);
    }

    /**
     * Expire points that have run out of time.
     *
     * Oldest first, and only as much as is still there: a customer who
     * already spent last year's points does not lose this year's for it.
     * That arithmetic is the whole of the method, and getting it the other
     * way round would quietly confiscate points people had earned.
     *
     * @return Collection<int, LoyaltyTransaction>
     */
    public function expire(?int $shopId = null): Collection
    {
        $written = collect();

        $lapsed = LoyaltyTransaction::query()
            ->when($shopId !== null, fn ($q) => $q->where('shop_id', $shopId))
            ->lapsed()
            ->with('customer')
            ->get()
            ->groupBy('customer_id');

        foreach ($lapsed as $customerId => $rows) {
            $customer = $rows->first()->customer;

            if ($customer === null) {
                continue;
            }

            $balance = $this->balance($customer);

            if ($balance <= 0) {
                continue;
            }

            /*
             | How much of what lapsed is still unspent.
             |
             | Capped at the balance, because the spending since has already
             | consumed some of it - and the row must never take more than
             | the customer actually has.
             */
            $toExpire = min($balance, (int) $rows->sum('points'));

            if ($toExpire <= 0) {
                continue;
            }

            // The earn rows are settled by writing one expiry row against
            // them; clearing the dates stops them lapsing twice.
            $written->push($this->write(
                customer: $customer,
                type: LoyaltyTransaction::EXPIRE,
                points: -$toExpire,
                note: 'Points expired',
            ));

            LoyaltyTransaction::query()
                ->whereIn('id', $rows->pluck('id'))
                ->update(['expires_at' => null]);
        }

        return $written;
    }

    /**
     * A customer's passbook.
     *
     * @return Collection<int, LoyaltyTransaction>
     */
    public function statement(Customer $customer, int $limit = 50): Collection
    {
        return LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /* ----------------------------------------------------------- writing */

    private function alreadyAwarded(Customer $customer, Model $source): bool
    {
        return LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('type', LoyaltyTransaction::EARN)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->exists();
    }

    /**
     * Append one row and stamp the running total on it.
     *
     * Locked, because two tills settling for the same regular at the same
     * second would otherwise both read the same `balance_after` and write a
     * passbook that does not add up - even though the balance itself, being
     * a sum, would still be right.
     */
    private function write(
        Customer $customer,
        string $type,
        int $points,
        ?Model $source = null,
        ?float $amount = null,
        ?\DateTimeInterface $expiresAt = null,
        ?string $note = null,
    ): LoyaltyTransaction {
        return DB::transaction(function () use ($customer, $type, $points, $source, $amount, $expiresAt, $note) {
            $previous = LoyaltyTransaction::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->sum('points');

            return LoyaltyTransaction::create([
                'shop_id' => $customer->shop_id ?? CurrentShop::idForWrite(),
                'customer_id' => $customer->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => (int) $previous + $points,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'amount' => $amount,
                'expires_at' => $expiresAt,
                'note' => $note,
                'user_id' => Auth::id(),
            ]);
        });
    }
}
