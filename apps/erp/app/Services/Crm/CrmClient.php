<?php

namespace App\Services\Crm;

use Illuminate\Http\Client\Response;
use Khana\Kit\Sso\MachineTokens;
use Khana\Kit\Support\Ulid;
use Throwable;

/**
 * What ERP reads from CRM (contracts/api/crm.yaml): an outlet's whole menu,
 * one trading day's sales in brief, and one sale in full.
 *
 * Reading is all it does, and only three things ask: the first menu sync,
 * the "pull the menu" button, and the nightly check. Nothing that saves ever
 * calls this - everything live arrives as events, which is why ERP keeps
 * working while CRM is down.
 *
 * Every way of not getting an answer is one exception, CrmUnavailable, with a
 * sentence saying which. One attempt, ten seconds: whoever asked decides
 * whether and when to ask again.
 */
final class CrmClient
{
    public function __construct(private readonly MachineTokens $tokens) {}

    /**
     * Every menu item CRM has for the outlet, archived ones included, each
     * in the shape of crm.menu.item_upserted's `data` plus `archived`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CrmUnavailable
     */
    public function menu(string $outletId): array
    {
        $items = $this->answer($this->get("/api/v1/outlets/{$outletId}/menu", ['menu:read']))['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new CrmUnavailable('Khana CRM sent a menu ERP could not read.');
        }

        foreach ($items as $item) {
            $readable = self::carries($item, ['item_id', 'name', 'price_paise', 'active'])
                && Ulid::isValid($item['item_id'])
                && self::each($item['sizes'] ?? [], ['variant_id', 'name', 'price_paise', 'is_default'])
                && self::each($item['addons'] ?? [], ['option_id', 'modifier_name', 'name', 'price_paise', 'active']);

            if (! $readable) {
                throw new CrmUnavailable('Khana CRM sent a menu ERP could not read.');
            }
        }

        return $items;
    }

    /**
     * One trading day at the outlet: how many sales stand, what they add up
     * to, their ids, and the ids of the day's sales cancelled since.
     *
     * @return array{count: int, grand_paise: int, sale_ids: list<string>, cancelled_sale_ids: list<string>}
     *
     * @throws CrmUnavailable
     */
    public function salesDigest(string $outletId, string $date): array
    {
        $digest = $this->answer($this->get("/api/v1/outlets/{$outletId}/sales-digest", ['sales:read'], ['date' => $date]));

        $readable = is_int($digest['count'] ?? null)
            && is_int($digest['grand_paise'] ?? null)
            && self::ids($digest['sale_ids'] ?? null)
            && self::ids($digest['cancelled_sale_ids'] ?? null);

        if (! $readable) {
            throw new CrmUnavailable('Khana CRM sent a summary of the day ERP could not read.');
        }

        return [
            'count' => $digest['count'],
            'grand_paise' => $digest['grand_paise'],
            'sale_ids' => $digest['sale_ids'],
            'cancelled_sale_ids' => $digest['cancelled_sale_ids'],
        ];
    }

    /**
     * One sale as crm.sale.recorded carried it, with whose it is and where
     * it stands now. Null when CRM has no such sale.
     *
     * @return array<string, mixed>|null
     *
     * @throws CrmUnavailable
     */
    public function sale(string $saleId): ?array
    {
        $response = $this->get("/api/v1/sales/{$saleId}", ['sales:read']);

        if ($response->status() === 404) {
            return null;
        }

        $sale = $this->answer($response);

        $readable = self::carries($sale, ['sale_id', 'invoice_no', 'business_date', 'channel', 'lines', 'totals', 'outlet_id'])
            && self::carries($sale['totals'], ['net_paise', 'tax_paise', 'grand_paise'])
            && is_array($sale['lines']) && $sale['lines'] !== []
            && self::each($sale['lines'], ['line_id', 'item_id', 'variant_id', 'option_ids', 'name', 'qty', 'net_paise', 'tax_paise', 'order_line_ids']);

        if (! $readable) {
            throw new CrmUnavailable('Khana CRM sent a sale ERP could not read.');
        }

        return $sale;
    }

    /* ---------------------------------------------------------- private */

    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $query
     *
     * @throws CrmUnavailable
     */
    private function get(string $path, array $scopes, array $query = []): Response
    {
        $crm = rtrim((string) config('khana.urls.crm'), '/');

        if ($crm === '') {
            throw new CrmUnavailable('No address for Khana CRM is set up here.');
        }

        try {
            $http = $this->tokens->http($scopes);
        } catch (Throwable $e) {
            throw new CrmUnavailable('Khana Accounts did not give ERP a pass to read CRM.', previous: $e);
        }

        try {
            return $http->get($crm.$path, $query);
        } catch (Throwable $e) {
            throw new CrmUnavailable('Khana CRM did not answer.', previous: $e);
        }
    }

    /**
     * The body of an answer, once it is one.
     *
     * @return array<string, mixed>
     *
     * @throws CrmUnavailable
     */
    private function answer(Response $response): array
    {
        if (! $response->successful()) {
            throw new CrmUnavailable('Khana CRM answered with an error ('.$response->status().').');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new CrmUnavailable('Khana CRM sent something ERP could not read.');
        }

        return $body;
    }

    /** @param  list<string>  $keys */
    private static function carries(mixed $row, array $keys): bool
    {
        if (! is_array($row)) {
            return false;
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $keys */
    private static function each(mixed $rows, array $keys): bool
    {
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! self::carries($row, $keys)) {
                return false;
            }
        }

        return true;
    }

    private static function ids(mixed $list): bool
    {
        if (! is_array($list) || ! array_is_list($list)) {
            return false;
        }

        foreach ($list as $id) {
            if (! Ulid::isValid($id)) {
                return false;
            }
        }

        return true;
    }
}
