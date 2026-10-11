<?php

namespace App\Services\Erp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Sso\MachineTokens;
use Throwable;

/**
 * Reads ERP for one outlet (contracts/api/erp.yaml).
 *
 * CRM asks ERP for something in exactly two situations: to draw a panel on a
 * screen, and in the nightly check that puts right a lost sold-out or cost
 * signal. Never while saving. A bill, an order or a paid-out is written from
 * what CRM already holds, so the till keeps working with ERP switched off -
 * which is why nothing here writes, and why every method only reads.
 *
 * What comes back is ERP's own shape, untouched: whole paise, decimal
 * strings and ULIDs. Turning that into rupees and CRM's names is the job of
 * whoever draws it (App\Http\Controllers\Admin\ErpPanelController).
 *
 * Every way of failing is one exception, ErpUnavailable, and an outlet that
 * is not linked to ERP is refused before anything is sent - so a caller that
 * forgot to ask ErpLink first still cannot make ERP hear about an outlet
 * that never bought it.
 */
final class ErpClient
{
    /**
     * Seconds a screen waits before it says ERP is not reachable. The panels
     * are fetched after their page has drawn, so this holds up one card and
     * never the page - but a cashier is still looking at that card.
     */
    private const SCREEN_TIMEOUT = 3;

    /** The nightly check is nobody's screen, and it reads a whole menu. */
    private const CHECK_TIMEOUT = 10;

    public function __construct(
        private readonly MachineTokens $tokens,
        private readonly ErpLink $link,
    ) {}

    /**
     * The supplier bills still owed, oldest due first, for the paid-out card.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ErpUnavailable
     */
    public function openBills(string $outletUlid): array
    {
        $path = 'supplier-bills';
        $body = $this->read($this->ask($outletUlid, $path, 'bills:read', ['status' => 'open']), $outletUlid, $path);

        return $this->listIn($body, 'bills', $outletUlid, $path);
    }

    /**
     * Stock on hand and recipe cost for one dish, size by size - or null
     * when ERP has not heard of the dish, which is an answer, not a failure.
     *
     * @return array<string, mixed>|null
     *
     * @throws ErpUnavailable
     */
    public function itemStock(string $outletUlid, string $itemUlid): ?array
    {
        $path = 'items/'.rawurlencode($itemUlid).'/stock';
        $response = $this->ask($outletUlid, $path, 'stock:read');

        return $response->status() === 404 ? null : $this->read($response, $outletUlid, $path);
    }

    /**
     * One business day's sales, what the food in them cost, and how many
     * dishes sold had no recipe. The outlet's current day when none is named.
     *
     * @return array<string, mixed>
     *
     * @throws ErpUnavailable
     */
    public function foodCost(string $outletUlid, ?string $date = null): array
    {
        $path = 'food-cost';

        return $this->read($this->ask($outletUlid, $path, 'stock:read', $date === null ? [] : ['date' => $date]), $outletUlid, $path);
    }

    /**
     * What ERP last told CRM about each dish - on sale or not, and its cost -
     * for the nightly check to compare with what CRM holds.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ErpUnavailable
     */
    public function signals(string $outletUlid): array
    {
        $path = 'signals';
        $body = $this->read($this->ask($outletUlid, $path, 'stock:read', timeout: self::CHECK_TIMEOUT), $outletUlid, $path);

        return $this->listIn($body, 'items', $outletUlid, $path);
    }

    /* --------------------------------------------------------- internals */

    /**
     * One GET, carrying a token Accounts issued for the scope. Whatever
     * status ERP answers with is handed back; not getting an answer at all
     * is ErpUnavailable.
     *
     * @param  array<string, string>  $query
     *
     * @throws ErpUnavailable
     */
    private function ask(string $outletUlid, string $path, string $scope, array $query = [], int $timeout = self::SCREEN_TIMEOUT): Response
    {
        if (! $this->link->linkedOutlet($outletUlid)) {
            throw $this->unavailable($outletUlid, $path, ErpLink::url() === null
                ? 'this install has no address for ERP (KHANA_ERP_URL)'
                : 'the outlet does not have ERP');
        }

        try {
            return $this->tokens->http([$scope])
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->get(ErpLink::url().'/api/v1/outlets/'.$outletUlid.'/'.$path, $query);
        } catch (Throwable $e) {
            // No token from Accounts, no connection, or no answer in time.
            throw $this->unavailable($outletUlid, $path, $e->getMessage(), $e);
        }
    }

    /**
     * The JSON object in a successful answer.
     *
     * @return array<string, mixed>
     *
     * @throws ErpUnavailable
     */
    private function read(Response $response, string $outletUlid, string $path): array
    {
        if (! $response->successful()) {
            throw $this->unavailable($outletUlid, $path, 'ERP answered '.$response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw $this->unavailable($outletUlid, $path, 'ERP answered with something that is not JSON');
        }

        return $body;
    }

    /**
     * The list a response is built around, with anything that is not an
     * entry dropped - a screen that loops over it must never meet a string.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     *
     * @throws ErpUnavailable
     */
    private function listIn(array $body, string $key, string $outletUlid, string $path): array
    {
        if (! is_array($body[$key] ?? null)) {
            throw $this->unavailable($outletUlid, $path, "ERP's answer has no \"{$key}\" list");
        }

        return array_values(array_filter($body[$key], 'is_array'));
    }

    /**
     * Written down as it happens. Nobody is paged for it - a panel says so
     * and the page carries on - but "ERP was down" should be something a
     * person can check afterwards rather than take a cashier's word for.
     */
    private function unavailable(string $outletUlid, string $path, string $reason, ?Throwable $previous = null): ErpUnavailable
    {
        Log::warning('ERP could not be read.', ['outlet' => $outletUlid, 'path' => $path, 'reason' => $reason]);

        return new ErpUnavailable("ERP could not be read ({$path}): {$reason}.", previous: $previous);
    }
}
