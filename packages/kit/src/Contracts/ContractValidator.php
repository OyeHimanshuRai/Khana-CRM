<?php

namespace Khana\Kit\Contracts;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Checks an event against the JSON Schema in contracts/events/.
 *
 * The same files are read by the sender's tests and the receiver's, which is
 * the whole point: neither side can drift from what the other expects
 * without a test on one of them going red.
 *
 * Schemas refer to each other through the made-up base URI below, which is
 * mapped onto the local contracts folder - nothing is ever fetched.
 */
final class ContractValidator
{
    public const BASE_URI = 'https://contracts.khana.local/';

    private ?Validator $validator = null;

    public function __construct(private readonly string $root) {}

    public function root(): string
    {
        return rtrim(str_replace('\\', '/', $this->root), '/');
    }

    public function eventSchemaPath(string $type, int $version): string
    {
        return $this->root().'/events/'.$type.'.v'.$version.'.json';
    }

    public function hasEventSchema(string $type, int $version): bool
    {
        return is_file($this->eventSchemaPath($type, $version));
    }

    /**
     * Everything wrong with an event, or nothing.
     *
     * @param  array<string, mixed>|object  $envelope
     * @return list<string>
     */
    public function problems(array|object $envelope): array
    {
        $json = self::toJson($envelope);

        $type = $json->type ?? null;
        $version = $json->version ?? null;

        if (! is_string($type) || ! is_int($version)) {
            return ['type and version are required'];
        }

        if (! $this->hasEventSchema($type, $version)) {
            return ["there is no contract for {$type} v{$version}"];
        }

        $result = $this->validator()->validate($json, self::BASE_URI."events/{$type}.v{$version}.json");

        if ($result->isValid()) {
            return [];
        }

        $problems = [];

        foreach ((new ErrorFormatter())->format($result->error()) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $problems[] = ($pointer === '' ? '/' : $pointer).': '.$message;
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>|object  $envelope
     */
    public function assertValid(array|object $envelope): void
    {
        $problems = $this->problems($envelope);

        if ($problems !== []) {
            $json = self::toJson($envelope);

            throw ContractViolation::for((string) ($json->type ?? '?'), (int) ($json->version ?? 0), $problems);
        }
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator();
            $this->validator->setMaxErrors(20);
            $this->validator->resolver()->registerPrefix(self::BASE_URI, $this->root().'/');
        }

        return $this->validator;
    }

    /**
     * JSON Schema needs JSON's own types: objects as objects, lists as lists.
     */
    private static function toJson(array|object $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}
