<?php

namespace App\Console\Commands;

use Database\Seeders\DemoContentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replace marketing copy left over from a different line of business.
 *
 * ---------------------------------------------------------------------------
 * Why a command and not just a seeder edit
 * ---------------------------------------------------------------------------
 *
 * DemoContentSeeder now writes restaurant copy, but a seeder only runs on a
 * fresh install - and every deployment that has already been seeded is still
 * carrying an agricultural dealer's FAQs, services, blog posts and banners.
 * "Do you deliver to my village?" and "How do I know a pesticide is genuine?"
 * sit under a heading that says Asked Often on a restaurant POS site, and the
 * contact panel still said "Let's Build the Right Jewelry Stack".
 *
 * That content is the first thing a prospective customer reads. Fixing the
 * seeder alone fixes nobody who already installed it.
 *
 * ---------------------------------------------------------------------------
 * It deletes, and it says so
 * ---------------------------------------------------------------------------
 *
 * The rows are demo copy, not business records - no invoice, no stock and no
 * customer depends on them - so they are removed and rewritten rather than
 * patched. But anything somebody has since edited by hand is in here too, and
 * this cannot tell the difference. So it asks first, it names what it will
 * throw away, and --dry-run shows the count without touching anything.
 *
 * Deliberately limited to marketing content. Products, suppliers, purchase
 * orders and every row that carries money are not touched - see dine:tidy for
 * the operational side.
 */
class RefreshDemoContent extends Command
{
    protected $signature = 'demo:refresh-content
                            {--dry-run : Report what would be replaced without changing anything}
                            {--force : Skip the confirmation}';

    protected $description = 'Replace demo FAQs, services, blogs, events, sliders and reels with restaurant copy';

    /** The tables this rewrites, and what each is called on screen. */
    private const TABLES = [
        'faqs' => 'FAQs',
        'services' => 'Services',
        'blogs' => 'Blog posts',
        'events' => 'Events',
        'sliders' => 'Sliders',
        'reels' => 'Reels',
        'collections' => 'Collections',
        'instagram_posts' => 'Instagram posts',
    ];

    public function handle(): int
    {
        $counts = [];

        foreach (self::TABLES as $table => $label) {
            $counts[$label] = DB::table($table)->count();
        }

        $total = array_sum($counts);

        $this->line('');

        foreach ($counts as $label => $count) {
            $this->line(sprintf('  %-18s %d', $label, $count));
        }

        $this->line('');

        if ($total === 0) {
            $this->info('Nothing to replace.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment(sprintf(
                '%d row(s) would be deleted and rewritten with restaurant copy. Nothing was changed.',
                $total,
            ));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Delete these %d row(s) and rewrite them? Anything edited by hand is lost.', $total)
        )) {
            $this->comment('Left alone.');

            return self::SUCCESS;
        }

        /*
         | Truncate would be tidier and is wrong here: several of these tables
         | are pointed at by foreign keys, and a delete respects them while a
         | truncate refuses outright on MySQL. The sets are a few dozen rows.
         */
        DB::transaction(function () {
            foreach (array_keys(self::TABLES) as $table) {
                DB::table($table)->delete();
            }
        });

        $this->call('db:seed', ['--class' => DemoContentSeeder::class, '--force' => true]);

        $this->line('');
        $this->info('Marketing copy replaced. Company settings are separate - see the note below.');
        $this->line('  Admin > Settings > Company still holds the contact heading and SEO titles.');

        return self::SUCCESS;
    }
}
