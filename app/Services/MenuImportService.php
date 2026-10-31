<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Support\CurrentShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bulk menu import (§8).
 *
 * ---------------------------------------------------------------------------
 * Why this exists at all
 * ---------------------------------------------------------------------------
 *
 * A restaurant signing up has a menu of three or four hundred dishes, already
 * written down somewhere. Typing it into a form four hundred times is not an
 * onboarding process, it is a reason to pick a different product.
 *
 * ---------------------------------------------------------------------------
 * All or nothing
 * ---------------------------------------------------------------------------
 *
 * Every row is validated before any row is written, and one bad row stops the
 * whole file. A half-imported menu is worse than none: the operator cannot
 * tell which rows landed, re-running would double what did, and the only way
 * back is to delete four hundred dishes by hand.
 *
 * The errors come back with the spreadsheet's own row numbers, because that is
 * what the person is looking at while they read them.
 *
 * ---------------------------------------------------------------------------
 * What it matches on
 * ---------------------------------------------------------------------------
 *
 * The SKU, and the name when there is no SKU. That makes the export/import
 * pair a round trip: download the menu, change forty prices in a spreadsheet,
 * upload it, and forty dishes are updated rather than four hundred duplicated.
 */
class MenuImportService
{
    /**
     * The columns, in the order the export writes them.
     *
     * The header row is matched case- and space-insensitively, so a file that
     * has been through Excel twice still imports - but the order here is what
     * a downloaded template looks like.
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'SKU', 'Name', 'Category', 'Sub-category', 'Brand', 'Unit', 'HSN', 'Tax',
        'Food Type', 'Spice', 'Serves', 'Prep Minutes',
        'Purchase Price', 'Selling Price', 'Dine-in Price', 'Takeaway Price', 'Delivery Price',
        'Tax Inclusive', 'Kitchen Station', 'Made To Order', 'Ingredient', 'Batch Tracked',
        'Available From', 'Available To', 'Active', 'Sold Out', 'Sort Order',
    ];

    /** How many rows one upload may carry. */
    public const MAX_ROWS = 2000;

    /**
     * Read a CSV and either import all of it or none of it.
     *
     * @return array{created: int, updated: int, categories: array<int, string>, rows: int}
     *
     * @throws RuntimeException with every row's problem, when any row fails
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $rows = $this->read($path);

        if ($rows->isEmpty()) {
            throw new RuntimeException('That file has no rows in it.');
        }

        if ($rows->count() > self::MAX_ROWS) {
            throw new RuntimeException(sprintf(
                'That file has %s rows. Split it into files of %s or fewer.',
                number_format($rows->count()),
                number_format(self::MAX_ROWS),
            ));
        }

        $lookups = $this->lookups();
        $errors = [];
        $plans = [];

        foreach ($rows as $index => $row) {
            // +2: one for the header, one because a spreadsheet counts from 1.
            $line = $index + 2;

            try {
                $plans[] = $this->plan($row, $lookups, $line);
            } catch (RuntimeException $e) {
                $errors[] = 'Row '.$line.': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            /*
             | Capped at fifteen. A file where every row is wrong - the wrong
             | columns, say - would otherwise return four hundred copies of
             | the same sentence, and the one useful line would be off the
             | bottom of the screen.
             */
            $shown = array_slice($errors, 0, 15);
            $more = count($errors) - count($shown);

            throw new RuntimeException(
                implode("\n", $shown).($more > 0 ? "\n… and {$more} more." : '')
            );
        }

        if ($dryRun) {
            return $this->tally($plans, []);
        }

        return DB::transaction(function () use ($plans) {
            $madeCategories = [];

            foreach ($plans as $plan) {
                $this->apply($plan, $madeCategories);
            }

            $tally = $this->tally($plans, $madeCategories);

            ActivityLog::record(
                'menu.imported',
                sprintf(
                    'Imported a menu file — %d created, %d updated%s',
                    $tally['created'],
                    $tally['updated'],
                    $madeCategories === [] ? '' : ', '.count($madeCategories).' new categories',
                ),
            );

            return $tally;
        });
    }

    /* -------------------------------------------------------------- reading */

    /**
     * The file, as rows keyed by their normalised header.
     *
     * @return Collection<int, array<string, string>>
     */
    private function read(string $path): Collection
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('That file could not be opened.');
        }

        $rows = collect();
        $header = null;

        while (($line = fgetcsv($handle)) !== false) {
            if ($header === null) {
                // Excel writes a byte-order mark, and a header that starts
                // with one matches nothing at all.
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);

                $header = array_map(fn ($cell) => $this->key((string) $cell), $line);

                if (! in_array('name', $header, true)) {
                    fclose($handle);

                    throw new RuntimeException(
                        'That file has no "Name" column. Download the template and start from that.'
                    );
                }

                continue;
            }

            // A trailing blank line is what a spreadsheet leaves behind, not
            // a row somebody meant.
            if (count(array_filter($line, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($header as $position => $name) {
                $row[$name] = trim((string) ($line[$position] ?? ''));
            }

            $rows->push($row);
        }

        fclose($handle);

        return $rows;
    }

    /** "Dine-in Price" and "dine in price" are the same column. */
    private function key(string $header): string
    {
        return Str::of($header)->lower()->replace(['-', '_'], ' ')->squish()->toString();
    }

    /* ------------------------------------------------------------ planning */

    /**
     * Everything the import needs to look names up against.
     *
     * Read once. A four-hundred-row file resolving a category by name per row
     * is four hundred queries for twelve distinct answers.
     *
     * @return array<string, Collection<string, mixed>>
     */
    private function lookups(): array
    {
        return [
            'categories' => Category::query()->get(['id', 'name', 'parent_id'])
                ->keyBy(fn (Category $c) => $this->key($c->name)),
            'brands' => Brand::query()->get(['id', 'name'])
                ->keyBy(fn (Brand $b) => $this->key($b->name)),
            'units' => Unit::query()->get(['id', 'name', 'code'])
                ->flatMap(fn (Unit $u) => [
                    $this->key($u->code) => $u->id,
                    $this->key($u->name) => $u->id,
                ]),
            'taxes' => TaxRate::query()->get(['id', 'name', 'rate'])
                ->flatMap(fn (TaxRate $t) => [
                    $this->key($t->name) => $t->id,
                    $this->key((string) (float) $t->rate) => $t->id,
                ]),
            'stations' => KitchenStation::query()->get(['id', 'name', 'code'])
                ->flatMap(fn (KitchenStation $s) => [
                    $this->key($s->name) => $s->id,
                    $this->key($s->code) => $s->id,
                ]),
        ];
    }

    /**
     * Turn one row into "create this" or "update that", or explain why not.
     *
     * @param  array<string, string>  $row
     * @param  array<string, Collection<string, mixed>>  $lookups
     * @return array<string, mixed>
     */
    private function plan(array $row, array $lookups, int $line): array
    {
        $name = $row['name'] ?? '';

        if ($name === '') {
            throw new RuntimeException('a dish needs a name.');
        }

        $sku = $row['sku'] ?? '';

        $existing = $sku !== ''
            ? Product::query()->where('sku', $sku)->first()
            : Product::query()->where('name', $name)->first();

        /*
         | A deleted dish still holds its SKU against the unique index, and a
         | soft-deleted row is invisible to the query above. Without this the
         | import would try to insert, hit the index, and hand somebody a raw
         | database error instead of a row number - and roll the whole file
         | back while doing it.
         |
         | Reported rather than restored. A dish was deleted for a reason, and
         | quietly bringing it back because a spreadsheet mentioned its code is
         | a surprise nobody asked for. The message says exactly what to do.
         */
        if ($existing === null && $sku !== '' && Product::withTrashed()->where('sku', $sku)->exists()) {
            throw new RuntimeException(sprintf(
                'a deleted dish already uses the SKU "%s". Restore it, or give this row a different SKU.',
                $sku,
            ));
        }

        $attributes = ['name' => $name];

        /*
         | A unit is required for a *new* dish and left alone on an update.
         | The column is often blank on a file somebody edited down to forty
         | prices, and clearing the unit of forty dishes would be a
         | catastrophic reading of a blank cell.
         */
        $unit = $this->resolve($lookups['units'], $row['unit'] ?? '', 'unit', $line);

        if ($unit !== null) {
            $attributes['unit_id'] = $unit;
        } elseif ($existing === null) {
            throw new RuntimeException('a new dish needs a unit (PCS, KG, PLT…).');
        }

        $tax = $this->resolve($lookups['taxes'], $row['tax'] ?? '', 'tax rate', $line);

        if ($tax !== null) {
            $attributes['tax_rate_id'] = $tax;
        }

        $station = $this->resolve($lookups['stations'], $row['kitchen station'] ?? '', 'kitchen station', $line);

        if ($station !== null) {
            $attributes['kitchen_station_id'] = $station;
        }

        $brand = $this->resolve($lookups['brands'], $row['brand'] ?? '', 'brand', $line, soft: true);

        if ($brand !== null) {
            $attributes['brand_id'] = $brand;
        }

        foreach ([
            'hsn' => 'hsn_code',
            'serves' => 'serves',
            'prep minutes' => 'prep_minutes',
            'purchase price' => 'purchase_price',
            'selling price' => 'selling_price',
            'dine in price' => 'dine_in_price',
            'takeaway price' => 'takeaway_price',
            'delivery price' => 'delivery_price',
            'sort order' => 'sort_order',
        ] as $column => $field) {
            if (($row[$column] ?? '') !== '') {
                $attributes[$field] = $row[$column];
            }
        }

        foreach ([
            'tax inclusive' => 'tax_inclusive',
            'made to order' => 'is_made_to_order',
            'ingredient' => 'is_ingredient',
            'batch tracked' => 'track_batches',
            'active' => 'is_active',
            'sold out' => 'is_sold_out',
        ] as $column => $field) {
            if (($row[$column] ?? '') !== '') {
                $attributes[$field] = $this->boolean($row[$column]);
            }
        }

        if (($row['food type'] ?? '') !== '') {
            $attributes['food_type'] = $this->oneOf(
                $row['food type'],
                array_keys(Product::FOOD_TYPES),
                'food type',
            );
        }

        if (($row['spice'] ?? '') !== '') {
            $attributes['spice_level'] = $this->spice($row['spice']);
        }

        foreach (['available from' => 'available_from', 'available to' => 'available_to'] as $column => $field) {
            if (($row[$column] ?? '') !== '') {
                $attributes[$field] = $this->time($row[$column], $column);
            }
        }

        return [
            'existing' => $existing,
            'sku' => $sku,
            'attributes' => $attributes,
            // Resolved at apply time, because a category named twice in one
            // file must be created once.
            'category' => $row['category'] ?? '',
            'sub_category' => $row['sub category'] ?? '',
        ];
    }

    /* ------------------------------------------------------------ applying */

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, string>  $madeCategories
     */
    private function apply(array $plan, array &$madeCategories): void
    {
        $attributes = $plan['attributes'];

        $categoryId = $this->categoryFor($plan['category'], $plan['sub_category'], $madeCategories);

        if ($categoryId !== null) {
            $attributes['category_id'] = $categoryId;
        }

        /** @var Product|null $product */
        $product = $plan['existing'];

        if ($product === null) {
            $product = new Product($attributes);
            $product->slug = Product::uniqueSlug($attributes['name']);
            $product->sku = $plan['sku'] !== '' ? $plan['sku'] : Product::generateSku($attributes['name']);
            $product->save();

            return;
        }

        $product->fill($attributes)->save();
    }

    /**
     * The category to file a dish under, creating what is missing.
     *
     * Created rather than refused, because a menu import's whole purpose is
     * onboarding and "create your twelve categories first" is a chore that
     * makes the import pointless. The count of new ones comes back with the
     * result, so a typo that invented "Dessrts" is visible immediately rather
     * than a month later.
     *
     * @param  array<int, string>  $made
     */
    private function categoryFor(string $parent, string $child, array &$made): ?int
    {
        if ($parent === '' && $child === '') {
            return null;
        }

        $parentId = null;

        if ($parent !== '') {
            $row = $this->findOrMakeCategory($parent, null, $made);
            $parentId = $row->id;
        }

        if ($child === '') {
            return $parentId;
        }

        return $this->findOrMakeCategory($child, $parentId, $made)->id;
    }

    /**
     * @param  array<int, string>  $made
     */
    private function findOrMakeCategory(string $name, ?int $parentId, array &$made): Category
    {
        $existing = Category::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = Category::query()->create([
            'name' => $name,
            'slug' => Category::uniqueSlug($name),
            'parent_id' => $parentId,
            'is_active' => true,
        ]);

        $made[] = $name;

        return $category;
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * @param  Collection<string, mixed>  $haystack
     */
    private function resolve(Collection $haystack, string $value, string $what, int $line, bool $soft = false): ?int
    {
        if ($value === '') {
            return null;
        }

        $found = $haystack->get($this->key($value));

        if ($found === null) {
            if ($soft) {
                // A brand nobody set up is not worth stopping a menu for.
                return null;
            }

            throw new RuntimeException(sprintf('there is no %s called "%s".', $what, $value));
        }

        return $found instanceof \Illuminate\Database\Eloquent\Model ? $found->id : (int) $found;
    }

    /** "Yes", "1", "true", "y" all mean the same thing to somebody in Excel. */
    private function boolean(string $value): bool
    {
        return in_array(Str::lower(trim($value)), ['1', 'y', 'yes', 'true', 'on'], true);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function oneOf(string $value, array $allowed, string $what): string
    {
        $key = Str::of($value)->lower()->replace([' ', '-'], '_')->toString();

        if (! in_array($key, $allowed, true)) {
            throw new RuntimeException(sprintf(
                '"%s" is not a %s. Use one of: %s.',
                $value,
                $what,
                implode(', ', $allowed),
            ));
        }

        return $key;
    }

    /**
     * Spice, by number or by name.
     *
     * Product::SPICE_LEVELS is keyed by integer - 0 to 3 - because that is
     * what the column stores and what sorts correctly. A spreadsheet will
     * carry either, and "Mild" is what somebody types.
     */
    private function spice(string $value): int
    {
        $value = trim($value);

        if (is_numeric($value)) {
            $level = (int) $value;

            if (! array_key_exists($level, Product::SPICE_LEVELS)) {
                throw new RuntimeException(sprintf(
                    '"%s" is not a spice level. Use 0 to %d.',
                    $value,
                    array_key_last(Product::SPICE_LEVELS),
                ));
            }

            return $level;
        }

        foreach (Product::SPICE_LEVELS as $level => $label) {
            if (Str::lower($label) === Str::lower($value)) {
                return $level;
            }
        }

        throw new RuntimeException(sprintf(
            '"%s" is not a spice level. Use a number 0-%d, or one of: %s.',
            $value,
            array_key_last(Product::SPICE_LEVELS),
            implode(', ', Product::SPICE_LEVELS),
        ));
    }

    private function time(string $value, string $what): string
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($value))) {
            throw new RuntimeException(sprintf('"%s" is not a time for %s. Use 24-hour HH:MM.', $value, $what));
        }

        return trim($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @param  array<int, string>  $categories
     * @return array{created: int, updated: int, categories: array<int, string>, rows: int}
     */
    private function tally(array $plans, array $categories): array
    {
        $created = 0;
        $updated = 0;

        foreach ($plans as $plan) {
            $plan['existing'] === null ? $created++ : $updated++;
        }

        return [
            'rows' => count($plans),
            'created' => $created,
            'updated' => $updated,
            'categories' => array_values(array_unique($categories)),
        ];
    }

    /**
     * The template, and the export, share one row builder.
     *
     * A file that downloads with different columns from the ones the import
     * reads is the single most common way a bulk import wastes an afternoon.
     *
     * @return array<int, string>
     */
    public function row(Product $product, ?int $shopId = null): array
    {
        $shopId ??= CurrentShop::id();

        $category = $product->category;
        $parent = $category?->parent;

        $yes = fn ($value) => $value ? 'Yes' : 'No';
        $num = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return [
            $product->sku,
            $product->name,
            // The parent is the Category column and the child the Sub-category
            // one, so a re-import rebuilds the same two levels it came from.
            $parent?->name ?? $category?->name,
            $parent ? $category?->name : '',
            $product->brand?->name,
            $product->unit?->code,
            $product->hsn_code,
            $product->taxRate?->name,
            $product->food_type,
            $product->spice_level === null ? '' : (Product::SPICE_LEVELS[$product->spice_level] ?? $product->spice_level),
            $product->serves,
            $product->prep_minutes,
            $num($product->purchase_price),
            $num($product->selling_price),
            $num($product->dine_in_price),
            $num($product->takeaway_price),
            $num($product->delivery_price),
            $yes($product->tax_inclusive),
            $product->kitchenStation?->code,
            $yes($product->is_made_to_order),
            $yes($product->is_ingredient),
            $yes($product->track_batches),
            $product->available_from,
            $product->available_to,
            $yes($product->is_active),
            $yes($product->is_sold_out),
            $product->sort_order,
        ];
    }
}
