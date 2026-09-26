<?php

namespace Khana\Kit\Support;

use Symfony\Component\Uid\Ulid as SymfonyUlid;

/**
 * The one identifier format that crosses a service boundary.
 *
 * Any service can mint one without asking another, and they sort by time.
 * Always the canonical upper-case form, so two services comparing the same id
 * as strings cannot disagree. Laravel's own HasUlids lower-cases; ids that
 * leave a service go through here instead.
 */
final class Ulid
{
    public const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    public static function generate(): string
    {
        return (new SymfonyUlid())->toBase32();
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
