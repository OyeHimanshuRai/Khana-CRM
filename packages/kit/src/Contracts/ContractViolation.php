<?php

namespace Khana\Kit\Contracts;

use RuntimeException;

final class ContractViolation extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function for(string $type, int $version, array $problems): self
    {
        return new self("{$type} v{$version} does not match its contract: ".implode('; ', $problems));
    }
}
