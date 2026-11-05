<?php

namespace App\Services\Rules;

/** The rule version that decided a transaction. Store versionId on the transaction so history is never rewritten. */
class ResolvedRule
{
    public function __construct(
        public readonly string $versionId,
        public readonly int $versionNumber,
        public readonly string $ruleSetCode,
        public readonly array $outcomes,      // outcome_key => typed value (flow id, text, decimal, integer or boolean)
    ) {}

    public function get(string $key, mixed $default = null): mixed { return $this->outcomes[$key] ?? $default; }
}
