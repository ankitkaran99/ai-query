<?php

namespace Scholar\AiQuery\Support;

use Scholar\AiQuery\Exceptions\InvalidQuerySpecException;

/**
 * The actual security boundary. Runs before any query is built and rejects
 * any field, relation, operator, or malformed value that isn't explicitly
 * allowed — regardless of what the LLM returned.
 *
 * Every level here is defensive about *shape*, not just content: this
 * library targets multiple LLM providers (including local/cheaper ones
 * that are less consistent about strictly following a JSON schema than,
 * say, Claude's structured output), so "filters isn't actually an array"
 * or "a filter is a string instead of an object" both need to fail with a
 * clear InvalidQuerySpecException — not a raw TypeError three calls deeper.
 */
final class QuerySpecValidator
{
    private const ALLOWED_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'in', 'between', 'like'];

    private const ALLOWED_AGGREGATES = ['count', 'list', 'sum', 'avg'];

    public function validate(array $spec, QueryableDefinition $definition): void
    {
        if (($spec['target'] ?? null) !== $definition->key) {
            throw new InvalidQuerySpecException('Query spec target does not match the requested queryable.');
        }

        foreach ($this->arrayOf($spec, 'filters') as $filter) {
            $filter = $this->assertObject($filter, 'filter');
            $this->validateField($filter['field'] ?? null, $definition->allowedColumns(), $definition->key);
            $this->validateOperatorAndValue($filter, $definition->key);
        }

        foreach ($this->arrayOf($spec, 'relation_filters') as $relationFilter) {
            $relationFilter = $this->assertObject($relationFilter, 'relation_filter');
            $relation = is_string($relationFilter['relation'] ?? null) ? $relationFilter['relation'] : null;
            $columns = $relation ? $definition->relationColumns($relation) : null;

            if ($columns === null) {
                throw new InvalidQuerySpecException('Relation "' . ($relation ?? '') . "\" is not allowed on \"{$definition->key}\".");
            }

            foreach ($this->arrayOf($relationFilter, 'filters') as $filter) {
                $filter = $this->assertObject($filter, 'filter');
                $this->validateField($filter['field'] ?? null, $columns, $relation);
                $this->validateOperatorAndValue($filter, $relation);
            }
        }

        $this->validateAggregate($spec, $definition);
    }

    private function validateAggregate(array $spec, QueryableDefinition $definition): void
    {
        $aggregate = $spec['aggregate'] ?? 'list';

        if (! is_string($aggregate) || ! in_array($aggregate, self::ALLOWED_AGGREGATES, true)) {
            throw new InvalidQuerySpecException('Unsupported aggregate "' . (is_scalar($aggregate) ? (string) $aggregate : gettype($aggregate)) . '".');
        }

        if (in_array($aggregate, ['sum', 'avg'], true)) {
            $this->validateField($spec['aggregate_field'] ?? null, $definition->allowedColumns(), $definition->key);
        }
    }

    /** $spec[$key], guaranteed to be an array — fails clearly instead of a TypeError on foreach if the LLM returned the wrong shape. */
    private function arrayOf(array $spec, string $key): array
    {
        $value = $spec[$key] ?? [];

        if (! is_array($value)) {
            throw new InvalidQuerySpecException("\"{$key}\" must be an array.");
        }

        return $value;
    }

    /** A single filter/relation_filter entry, guaranteed to be an associative array. */
    private function assertObject(mixed $item, string $label): array
    {
        if (! is_array($item)) {
            throw new InvalidQuerySpecException("Each \"{$label}\" entry must be an object, got " . gettype($item) . '.');
        }

        return $item;
    }

    private function validateField(mixed $field, array $allowed, string $context): void
    {
        if (! is_string($field) || $field === '' || ! in_array($field, $allowed, true)) {
            $shown = is_scalar($field) ? (string) $field : gettype($field);
            throw new InvalidQuerySpecException("Field \"{$shown}\" is not allowed on \"{$context}\".");
        }
    }

    private function validateOperatorAndValue(array $filter, string $context): void
    {
        $operator = $filter['operator'] ?? null;

        if (! is_string($operator) || ! in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $shown = is_scalar($operator) ? (string) $operator : gettype($operator);
            throw new InvalidQuerySpecException("Operator \"{$shown}\" is not allowed.");
        }

        $rawValue = $filter['value'] ?? null;

        if (! is_scalar($rawValue)) {
            throw new InvalidQuerySpecException("Filter value on \"{$context}\" must be a string, got " . gettype($rawValue) . '.');
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $rawValue)),
            fn ($v) => $v !== ''
        ));

        if ($operator === 'between' && count($parts) !== 2) {
            throw new InvalidQuerySpecException("\"between\" on \"{$context}\" requires exactly two comma-separated values, got " . count($parts) . '.');
        }

        if ($operator === 'in' && count($parts) < 1) {
            throw new InvalidQuerySpecException("\"in\" on \"{$context}\" requires at least one value.");
        }
    }
}
