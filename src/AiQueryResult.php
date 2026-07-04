<?php

namespace Scholar\AiQuery;

use Illuminate\Support\Collection;
use Scholar\AiQuery\Support\TokenUsage;

final class AiQueryResult
{
    public function __construct(
        public readonly string $prompt,
        public readonly array $spec,
        public readonly Collection $results,
        public readonly ?string $summary,
        public readonly bool $fromCachedSpec,
        // Which aggregate ran, if any — kept separate from the value on
        // purpose. AVG() over zero matching rows is NULL in SQL (unlike
        // COUNT, which is 0, and SUM, which Eloquent coalesces to 0); if
        // "is this an aggregate" were inferred from "is the value
        // non-null", a zero-row average would look identical to "this
        // wasn't an aggregate query at all". Tracking the type explicitly
        // avoids that ambiguity.
        public readonly ?string $aggregateType = null,
        public readonly int|float|null $aggregateValue = null,
        // Summed across every underlying Prism call this result actually
        // triggered — zero for the spec-generation part of a cache hit,
        // since no LLM call happened for it that time.
        public readonly TokenUsage $tokenUsage = new TokenUsage(),
    ) {
    }

    public function isAggregate(): bool
    {
        return $this->aggregateType !== null;
    }

    /** Number of rows actually fetched. Always 0 for aggregate results. */
    public function count(): int
    {
        return $this->results->count();
    }

    public function isEmpty(): bool
    {
        return match ($this->aggregateType) {
            'count' => $this->aggregateValue === 0,
            'avg' => $this->aggregateValue === null, // NULL avg = no matching rows
            'sum' => false, // 0 is a legitimate sum over real rows, not "no data"
            default => $this->results->isEmpty(),
        };
    }

    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'spec' => $this->spec,
            'results' => $this->results->toArray(),
            'aggregate_type' => $this->aggregateType,
            'aggregate_value' => $this->aggregateValue,
            'summary' => $this->summary,
            'from_cached_spec' => $this->fromCachedSpec,
            'token_usage' => $this->tokenUsage->toArray(),
        ];
    }
}
