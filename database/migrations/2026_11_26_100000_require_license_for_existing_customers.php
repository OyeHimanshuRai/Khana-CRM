<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customers that were trading before licences existed now need one too.
 *
 * The first licence migration exempted every company that existed at the time
 * so nobody was locked out by surprise. The platform has now decided that no
 * customer works without a licence, so each of those companies becomes a
 * licence-required one: it gets a "Not activated" licence (plan and paid term
 * carried over) and is asked for a key at its next request, exactly like a
 * company that has just registered. The Super Admin issues the keys from the
 * Companies list.
 *
 * The in-house company (code MAIN, the platform's own) stays exempt - it is
 * the platform, not a customer. A company already holding a licence is left
 * alone, so this is safe to run twice.
 */
return new class extends Migration
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        $tenants = DB::table('tenants')
            ->where('license_exempt', true)
            ->where('code', '!=', 'MAIN')
            ->get(['id', 'name']);

        foreach ($tenants as $tenant) {
            DB::transaction(function () use ($tenant) {
                if (! DB::table('licenses')->where('tenant_id', $tenant->id)->exists()) {
                    $this->issue($tenant);
                }

                DB::table('tenants')->where('id', $tenant->id)->update(['license_exempt' => false]);
            });
        }
    }

    /** A "Not activated" licence on the plan the company is already on. */
    private function issue(object $tenant): void
    {
        $subscription = DB::table('subscriptions')->where('tenant_id', $tenant->id)->orderByDesc('id')->first(['plan_id', 'ends_at']);

        $planId = $subscription->plan_id
            ?? DB::table('plans')->whereNull('deleted_at')->whereNull('tenant_id')->orderBy('sort_order')->value('id');

        if ($planId === null) {
            // No plan to put on it: the company is still licence-required, and the
            // Super Admin gives it one from the Companies list.
            return;
        }

        $key = $this->freshKey();

        // Keep a paid term that is still running; otherwise the term starts on approval.
        $keepsTerm = $subscription?->ends_at !== null && $subscription->ends_at > now()->toDateTimeString();

        $licenseId = DB::table('licenses')->insertGetId([
            'key_hash' => hash_hmac('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key)), (string) config('app.key')),
            'key_hint' => $this->hint($key),
            'plan_id' => $planId,
            'tenant_id' => $tenant->id,
            'holder_tenant_id' => null,
            'status' => 'issued',
            'validity_days' => 365,
            'expires_at' => $keepsTerm ? $subscription->ends_at : null,
            'notes' => 'Created for an existing customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('license_logs')->insert([
            'license_id' => $licenseId,
            'tenant_id' => $tenant->id,
            'action' => 'registered',
            'description' => $tenant->name.' now needs a license; created and waiting for a key to be issued',
            'created_at' => now(),
        ]);
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

    private function hint(string $key): string
    {
        $parts = explode('-', $key);

        return implode('-', [$parts[0], $parts[1], '****', '****', $parts[4]]);
    }

    /**
     * Not reversed: which companies were exempt before, as against exempted
     * by hand since, is not recorded. Exempt a company again from Companies.
     */
    public function down(): void
    {
        // Deliberately empty. See the note on up().
    }
};
