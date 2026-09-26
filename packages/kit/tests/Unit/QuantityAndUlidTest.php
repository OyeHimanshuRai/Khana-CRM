<?php

namespace Khana\Kit\Tests\Unit;

use InvalidArgumentException;
use Khana\Kit\Support\Quantity;
use Khana\Kit\Support\Ulid;
use PHPUnit\Framework\TestCase;

class QuantityAndUlidTest extends TestCase
{
    public function test_quantities_are_written_with_four_places(): void
    {
        $this->assertSame('2.0000', Quantity::of('2')->toString());
        $this->assertSame('0.2500', Quantity::of('0.25')->toString());
        $this->assertSame('3.0000', Quantity::of(3)->toString());
        $this->assertSame('-1.5000', Quantity::of('-1.5')->toString());
        $this->assertSame('"0.2500"', json_encode(Quantity::of('0.25')));
    }

    public function test_decimal_arithmetic_is_exact(): void
    {
        $this->assertTrue(Quantity::of('0.1')->plus('0.2')->equals('0.3'));
        $this->assertTrue(Quantity::of('1')->minus('1.0000')->isZero());
    }

    public function test_a_fifth_decimal_place_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Quantity::of('1.23456');
    }

    public function test_generated_ulids_are_canonical(): void
    {
        $id = Ulid::generate();

        $this->assertSame(26, strlen($id));
        $this->assertSame(strtoupper($id), $id);
        $this->assertTrue(Ulid::isValid($id));
        $this->assertNotSame($id, Ulid::generate());
    }

    public function test_non_canonical_ulids_are_refused(): void
    {
        $this->assertFalse(Ulid::isValid(strtolower(Ulid::generate())));
        $this->assertFalse(Ulid::isValid('01M3D669MZPZP9EP7ND2XFY1W'));
        $this->assertFalse(Ulid::isValid('01M3D669MZPZP9EP7ND2XFY1WI'));
        $this->assertFalse(Ulid::isValid('81M3D669MZPZP9EP7ND2XFY1W2'));
        $this->assertFalse(Ulid::isValid(null));
    }
}
