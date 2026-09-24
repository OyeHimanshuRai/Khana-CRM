<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Take out what the removed modules owned.
     *
     * Jewellery (rate board, metal ledger, old gold, stones), manufacturing
     * and karigar job-work, gold loans, HR, and newsletter subscribers and
     * campaigns were dropped when the platform was re-scoped to restaurant
     * POS and QR ordering. Their `create` migrations went with the code, so a
     * fresh database never builds any of this; this migration exists for the
     * databases that already have it, where deleting the migration file alone
     * would have left the tables sitting there forever.
     *
     * Everything is guarded - dropIfExists, hasColumn - so it is a no-op on a
     * fresh install and survives a database that is half-way between the two.
     *
     * Irreversible on purpose. `down()` cannot honestly recreate a schema
     * whose definition no longer exists in the codebase, and a stub that made
     * empty tables would be worse than saying so.
     */
    public function up(): void
    {
        /*
         | Grouped by module and listed child-before-parent, but the drop runs
         | with foreign keys off and does not rely on that order: `employees`
         | and `departments` reference each other (a department has a head, an
         | employee has a department), and no ordering satisfies a cycle.
         |
         | Switching the keys off is safe precisely because every one of these
         | tables is going. Nothing survives to hold a dangling reference -
         | the columns that pointed at them from `products`, `invoices`,
         | `invoice_items` and `customers` come off below.
         */
        $tables = [
            // Gold loan.
            'loan_transactions',
            'pledge_items',
            'pledges',
            'loan_accounts',

            // Manufacturing / karigar job-work.
            'karigar_return_items',
            'karigar_returns',
            'material_issue_items',
            'material_issues',
            'job_order_items',
            'job_orders',
            'karigar_ledgers',
            'karigars',

            // Jewellery core.
            'old_gold_items',
            'old_gold_purchases',
            'metal_ledger_entries',
            'metal_balances',
            'metal_rates',
            'product_stones',
            'stone_types',

            // HR.
            'leave_requests',
            'attendances',
            'employees',
            'departments',

            // Newsletter.
            'newsletter_campaign_recipients',
            'newsletter_campaigns',
            'newsletter_subscribers',
        ];

        Schema::withoutForeignKeyConstraints(function () use ($tables) {
            foreach ($tables as $table) {
                Schema::dropIfExists($table);
            }
        });

        $this->dropColumns('products', [
            'design_code', 'huid',
            'metal', 'purity',
            'gross_weight', 'stone_weight', 'less_weight', 'net_weight',
            'making_charge_type', 'making_charge_value',
            'wastage_charge_type', 'wastage_charge_value',
            'stone_value', 'stone_carat',
            // Ring size and pieces-per-article, added by the same migration.
            'size', 'pieces',
            'price_mode',
        ]);

        $this->dropColumns('invoice_items', [
            'metal', 'purity', 'fineness',
            'gross_weight', 'stone_weight', 'less_weight', 'net_weight', 'fine_weight',
            'metal_rate', 'metal_value',
            'making_charge_type', 'making_charge_value', 'making_amount',
            'wastage_charge_type', 'wastage_charge_value', 'wastage_amount', 'wastage_weight',
            'stone_amount', 'other_charges', 'price_mode',
        ]);

        $this->dropColumns('invoices', [
            'metal_total', 'making_total', 'wastage_total', 'stone_total',
            'other_charges_total',
            'old_gold_total', 'old_gold_fine_weight',
            'total_gross_weight', 'total_net_weight', 'total_fine_weight',
        ]);

        /*
         | KYC was added for pledging gold, not for billing, and goes with the
         | loan book. date_of_birth and occupation came in on the same
         | migration; they are dropped with it rather than orphaned, and
         | whatever the CRM work needs should be added deliberately rather
         | than inherited from a module that no longer exists.
         */
        $this->dropCustomerKyc();
    }

    /**
     * Drop only the columns that are actually there.
     *
     * A database built after the code was removed has none of them and one
     * built before has all of them - but a database part-way between the two
     * is exactly the case this has to survive, so each column is checked
     * rather than assumed.
     *
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present) {
            $blueprint->dropColumn($present);
        });
    }

    /**
     * The KYC block, which needs its index and foreign key taken off first.
     *
     * MySQL refuses to drop a column an index or a constraint still names, so
     * `kyc_verified_by` and the (shop_id, kyc_type) index have to go before
     * the plain dropColumn below can run.
     */
    private function dropCustomerKyc(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'kyc_type')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if ($this->hasIndex('customers', 'customers_shop_id_kyc_type_index')) {
                $table->dropIndex(['shop_id', 'kyc_type']);
            }
        });

        if (Schema::hasColumn('customers', 'kyc_verified_by')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('kyc_verified_by');
            });
        }

        $this->dropColumns('customers', [
            'kyc_type', 'kyc_number', 'kyc_document_path', 'kyc_photo_path',
            'kyc_verified_at',
            'guardian_name', 'guardian_relation', 'guardian_mobile',
            'date_of_birth', 'occupation',
        ]);
    }

    /**
     * Whether a named index exists on a table.
     *
     * Asked of the driver rather than assumed, because SQLite (the test
     * database) and MySQL name and report indexes differently, and dropping
     * one that is not there aborts the migration.
     */
    private function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();

        try {
            foreach ($connection->getSchemaBuilder()->getIndexes($table) as $existing) {
                if (($existing['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // A driver that cannot be asked is treated as "not there": the
            // column drop below will report the real problem if there is one.
            return false;
        }

        return false;
    }
};
