<?php

namespace App\Services\Nightly;

use App\Models\NightlyCheck;

/**
 * What one nightly check concluded: how it ended, how many differences it
 * found and put right, and a sentence or two a manager can read.
 *
 * Each check hands one of these back and App\Services\NightlyChecks writes
 * it down. Four endings, and only one of them is unfinished:
 *
 *   ok        nothing differed
 *   repaired  something differed, and all of it was put right
 *   skipped   the check does not apply to this outlet
 *   problem   something is left that the check could not, or must not, put
 *             right by itself - it is run again on the next pass
 */
final class Finding
{
    private function __construct(
        public readonly string $status,
        public readonly int $found,
        public readonly int $repaired,
        public readonly string $detail,
    ) {}

    public static function ok(string $detail): self
    {
        return new self(NightlyCheck::OK, 0, 0, $detail);
    }

    public static function repaired(int $count, string $detail): self
    {
        return new self(NightlyCheck::REPAIRED, $count, $count, $detail);
    }

    public static function skipped(string $detail): self
    {
        return new self(NightlyCheck::SKIPPED, 0, 0, $detail);
    }

    public static function problem(int $found, int $repaired, string $detail): self
    {
        return new self(NightlyCheck::PROBLEM, $found, $repaired, $detail);
    }

    public function isProblem(): bool
    {
        return $this->status === NightlyCheck::PROBLEM;
    }
}
