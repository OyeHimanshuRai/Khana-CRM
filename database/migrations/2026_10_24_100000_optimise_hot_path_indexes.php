<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes for the screens a busy service actually hammers, and
     * the single-column indexes they make redundant.
     *
     * ---------------------------------------------------------------------
     * What is added, and which query it is for
     * ---------------------------------------------------------------------
     *
     * order_items (order_id, kitchen_status)
     *   KitchenService::feed() and counts() both run `whereHas('items',
     *   outstanding)` - a correlated subquery of order_id = ? AND
     *   kitchen_status IN (...) for every order on the board - and
     *   KitchenService::rollUp() reads one order's kitchen lines on every
     *   bump. The old order_id index found the order's lines and then read
     *   each one to check its status; this answers from the index alone.
     *
     * orders (shop_id, status, placed_at)
     *   The live-orders screen, the POS rail and the dashboard all filter one
     *   shop's orders by status and read them oldest first. (shop_id, status)
     *   stopped at the filter and left the sort to a filesort.
     *
     * jobs (queue, reserved_at, available_at)
     *   The worker's poll: queue = ? AND (reserved_at IS NULL AND
     *   available_at <= ?) OR reserved_at <= ?. Print jobs, reminders and
     *   campaigns all go through it, every few seconds, all day.
     *
     * ---------------------------------------------------------------------
     * What is dropped, and why it is safe
     * ---------------------------------------------------------------------
     *
     * Every index removed below is the left-hand prefix of another index on
     * the same table, so any query that could use it uses the longer one
     * instead. Keeping both buys nothing on a read and costs an extra B-tree
     * write on every insert and update - and orders, order_items and jobs are
     * the most-written tables in the system.
     *
     * None of them backs a foreign key on its own: where a column is a
     * foreign key (order_items.order_id), the new composite starts with it
     * and takes over.
     */

    /** table => [columns to add, columns of the index it replaces] */
    private const REPLACE = [
        'order_items' => [['order_id', 'kitchen_status'], ['order_id']],
        'orders' => [['shop_id', 'status', 'placed_at'], ['shop_id', 'status']],
        'jobs' => [['queue', 'reserved_at', 'available_at'], ['queue']],
    ];

    /** Left-prefix duplicates: table => columns of the redundant index. */
    private const REDUNDANT = [
        ['blocked_ips', ['ip_address']],
        ['blogs', ['status']],
        ['campaigns', ['status']],
        ['email_logs', ['status']],
        ['email_templates', ['is_active']],
        ['faqs', ['category']],
        ['loyalty_transactions', ['type']],
        ['pages', ['is_active']],
        ['payment_reminders', ['status']],
        ['print_jobs', ['printable_type', 'printable_id']],
        ['products', ['is_active']],
        ['sliders', ['layout']],
    ];

    public function up(): void
    {
        foreach (self::REPLACE as $name => [$add, $old]) {
            Schema::table($name, function (Blueprint $table) use ($name, $add, $old) {
                if (! Schema::hasIndex($name, $add)) {
                    $table->index($add);
                }
            });

            // Separate statement: the composite must exist before the old
            // index goes, or MySQL refuses to leave a foreign key uncovered.
            Schema::table($name, function (Blueprint $table) use ($name, $old) {
                if (Schema::hasIndex($name, $old)) {
                    $table->dropIndex($old);
                }
            });
        }

        foreach (self::REDUNDANT as [$name, $columns]) {
            if (Schema::hasIndex($name, $columns)) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex($columns));
            }
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as [$name, $columns]) {
            if (! Schema::hasIndex($name, $columns)) {
                Schema::table($name, fn (Blueprint $table) => $table->index($columns));
            }
        }

        foreach (self::REPLACE as $name => [$add, $old]) {
            Schema::table($name, function (Blueprint $table) use ($name, $old) {
                if (! Schema::hasIndex($name, $old)) {
                    $table->index($old);
                }
            });

            Schema::table($name, function (Blueprint $table) use ($name, $add) {
                if (Schema::hasIndex($name, $add)) {
                    $table->dropIndex($add);
                }
            });
        }
    }
};
