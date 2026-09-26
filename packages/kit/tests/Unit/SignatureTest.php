<?php

namespace Khana\Kit\Tests\Unit;

use Khana\Kit\Events\Signature;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    private const BODY = '{"events":[]}';

    public function test_a_signature_verifies_with_the_same_secret(): void
    {
        $header = Signature::sign(self::BODY, 'secret', 1_000_000);

        $this->assertMatchesRegularExpression('/^t=1000000,v1=[0-9a-f]{64}$/', $header);
        $this->assertTrue(Signature::verify($header, self::BODY, 'secret', 300, 1_000_100));
    }

    public function test_a_changed_body_or_secret_fails(): void
    {
        $header = Signature::sign(self::BODY, 'secret', 1_000_000);

        $this->assertFalse(Signature::verify($header, '{"events":[1]}', 'secret', 300, 1_000_000));
        $this->assertFalse(Signature::verify($header, self::BODY, 'other', 300, 1_000_000));
    }

    public function test_anything_older_than_the_tolerance_is_refused(): void
    {
        $header = Signature::sign(self::BODY, 'secret', 1_000_000);

        $this->assertTrue(Signature::verify($header, self::BODY, 'secret', 300, 1_000_300));
        $this->assertFalse(Signature::verify($header, self::BODY, 'secret', 300, 1_000_301));
        $this->assertFalse(Signature::verify($header, self::BODY, 'secret', 300, 999_699));
    }

    public function test_the_time_cannot_be_swapped_for_a_fresh_one(): void
    {
        $header = Signature::sign(self::BODY, 'secret', 1_000_000);
        $replayed = str_replace('t=1000000', 't=2000000', $header);

        $this->assertFalse(Signature::verify($replayed, self::BODY, 'secret', 300, 2_000_000));
    }

    public function test_either_of_two_signatures_passes_during_a_secret_rotation(): void
    {
        $old = Signature::sign(self::BODY, 'old', 1_000_000);
        $new = Signature::sign(self::BODY, 'new', 1_000_000);
        $both = $new.','.substr($old, strpos($old, 'v1='));

        $this->assertTrue(Signature::verify($both, self::BODY, 'old', 300, 1_000_000));
        $this->assertTrue(Signature::verify($both, self::BODY, 'new', 300, 1_000_000));
    }

    public function test_missing_or_malformed_headers_fail(): void
    {
        $this->assertFalse(Signature::verify(null, self::BODY, 'secret'));
        $this->assertFalse(Signature::verify('', self::BODY, 'secret'));
        $this->assertFalse(Signature::verify('v1=abc', self::BODY, 'secret'));
        $this->assertFalse(Signature::verify('t=abc,v1=abc', self::BODY, 'secret'));
        $this->assertFalse(Signature::verify(Signature::sign(self::BODY, ''), self::BODY, ''));
    }
}
