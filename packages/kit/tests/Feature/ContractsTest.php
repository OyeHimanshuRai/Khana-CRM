<?php

namespace Khana\Kit\Tests\Feature;

use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Tests\TestCase;

/**
 * The contracts themselves: every event the architecture names has a schema,
 * every schema has a sample, and the samples pass - so a sender's test and a
 * receiver's test that both check against these files are checking against
 * something that holds together.
 */
class ContractsTest extends TestCase
{
    /** The sixteen events of version 1, plus the diagnostic ping. */
    private const EVENTS = [
        'accounts.tenant.upserted',
        'accounts.outlet.upserted',
        'accounts.user.upserted',
        'accounts.access.changed',
        'accounts.entitlement.changed',
        'accounts.sessions.revoked',
        'crm.menu.item_upserted',
        'crm.menu.item_archived',
        'crm.kitchen.line_advanced',
        'crm.kitchen.line_cancelled',
        'crm.sale.recorded',
        'crm.sale.cancelled',
        'crm.sale.returned',
        'crm.cash.paid_out',
        'erp.availability.changed',
        'erp.cost.changed',
        'system.ping',
    ];

    private function contracts(): ContractValidator
    {
        return $this->app->make(ContractValidator::class);
    }

    public function test_every_named_event_has_a_schema_and_a_valid_sample(): void
    {
        foreach (self::EVENTS as $type) {
            $this->assertTrue($this->contracts()->hasEventSchema($type, 1), "No schema for {$type} v1.");
            $this->assertSame([], $this->contracts()->problems(self::fixture($type)), "The {$type} sample does not pass its own schema.");
        }
    }

    public function test_no_schema_or_sample_exists_that_is_not_accounted_for(): void
    {
        $schemas = array_map(
            fn (string $path) => basename($path, '.json'),
            glob(self::contractsPath().'/events/*.v*.json'),
        );

        $fixtures = array_map(
            fn (string $path) => basename($path, '.json'),
            glob(self::contractsPath().'/fixtures/events/*.json'),
        );

        sort($schemas);
        sort($fixtures);

        $this->assertSame($schemas, $fixtures, 'Every schema needs a sample, and every sample a schema.');
        $this->assertCount(count(self::EVENTS), $schemas);
    }

    public function test_the_kit_envelope_check_agrees_with_every_sample(): void
    {
        foreach (self::EVENTS as $type) {
            $envelope = Envelope::fromArray(self::fixture($type));

            $this->assertSame($type, $envelope->type);
            $this->assertSame([], $this->contracts()->problems($envelope->toArray()));
        }
    }

    public function test_a_fraction_of_a_paisa_is_refused(): void
    {
        // JSON has one number type: 565.0 is the integer 565 on the wire. What
        // the contract must stop is money that is not whole paise.
        $sale = self::fixture('crm.sale.recorded');
        $sale['data']['totals']['grand_paise'] = 565.5;

        $this->assertNotSame([], $this->contracts()->problems($sale));
    }

    public function test_a_lower_case_id_is_refused(): void
    {
        $sale = self::fixture('crm.sale.recorded');
        $sale['data']['sale_id'] = strtolower($sale['data']['sale_id']);

        $this->assertNotSame([], $this->contracts()->problems($sale));
    }

    public function test_a_sale_must_name_its_outlet(): void
    {
        $sale = self::fixture('crm.sale.recorded');
        $sale['outlet_id'] = null;

        $this->assertNotSame([], $this->contracts()->problems($sale));
    }

    public function test_only_crm_may_send_a_sale(): void
    {
        $sale = self::fixture('crm.sale.recorded');
        $sale['source'] = 'erp';

        $this->assertNotSame([], $this->contracts()->problems($sale));
    }

    public function test_an_unknown_field_is_refused(): void
    {
        $sale = self::fixture('crm.sale.cancelled');
        $sale['data']['refund'] = true;

        $this->assertNotSame([], $this->contracts()->problems($sale));
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $line = self::fixture('crm.kitchen.line_advanced');
        $line['data']['qty'] = '0.0000';

        $this->assertNotSame([], $this->contracts()->problems($line));
    }

    public function test_quantities_are_strings_not_numbers(): void
    {
        $line = self::fixture('crm.kitchen.line_advanced');
        $line['data']['qty'] = 2;

        $this->assertNotSame([], $this->contracts()->problems($line));
    }

    public function test_an_event_without_a_contract_is_reported(): void
    {
        $event = self::fixture('system.ping');
        $event['type'] = 'crm.sale.reversed';

        $this->assertSame(['there is no contract for crm.sale.reversed v1'], $this->contracts()->problems($event));
    }
}
