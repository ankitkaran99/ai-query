<?php

namespace Scholar\AiQuery\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns a validated query spec into a real Eloquent query. This is the
 * replacement for "wrap LLM-generated PHP in a closure and eval it" — every
 * method called here is one you wrote and can unit-test, not something the
 * model produced.
 */
final class QueryBuilder
{
    public function build(array $spec, QueryableDefinition $definition): Builder
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = $definition->modelClass;
        $query = $modelClass::query();

        // Only ever select known-safe columns, so nothing unregistered can
        // leak through even if the model has other columns.
        $keyName = (new $modelClass)->getKeyName();
        $query->select(array_values(array_unique([$keyName, ...$definition->allowedColumns()])));

        foreach ($spec['filters'] ?? [] as $filter) {
            $this->applyFilter($query, $filter);
        }

        foreach ($this->mergeRelationFilters($spec['relation_filters'] ?? []) as $relationFilter) {
            $this->applyRelationFilter($query, $relationFilter, $definition);
        }

        // Applied last on purpose: nothing above this line can override it.
        if ($scope = $definition->getScope()) {
            $scope($query);
        }

        return $query;
    }

    /**
     * BUG FIX: if the LLM emits two relation_filters entries for the same
     * relation (e.g. one for "status = unpaid" and another for
     * "amount > 100" on feePayments), applying them independently is wrong
     * two ways: (1) two separate whereHas() calls become two independent
     * EXISTS subqueries — "some payment is unpaid" AND "some payment is
     * >100" — which can each be satisfied by a *different* row, not
     * necessarily one row matching both; and (2) two separate with()
     * calls for the same relation key silently overwrite each other in
     * Eloquent's eager-load array, so the first constraint's columns are
     * lost entirely. Merging first means one EXISTS subquery and one
     * with() call, with all filters correctly ANDed together.
     *
     * @param array<int, array{relation: string, filters?: array}> $relationFilters
     * @return array<int, array{relation: string, filters: array}>
     */
    private function mergeRelationFilters(array $relationFilters): array
    {
        $merged = [];

        foreach ($relationFilters as $relationFilter) {
            $name = $relationFilter['relation'];
            $merged[$name]['relation'] = $name;
            $merged[$name]['filters'] = array_merge(
                $merged[$name]['filters'] ?? [],
                $relationFilter['filters'] ?? []
            );
        }

        return array_values($merged);
    }

    private function applyRelationFilter(Builder $query, array $relationFilter, QueryableDefinition $definition): void
    {
        $relationName = $relationFilter['relation'];
        $columns = $definition->relationColumns($relationName) ?? [];

        $constraint = function (Builder $q) use ($relationFilter): void {
            foreach ($relationFilter['filters'] ?? [] as $filter) {
                $this->applyFilter($q, $filter);
            }
        };

        // whereHas constrains the parent query.
        $query->whereHas($relationName, $constraint);

        if ($columns === []) {
            $query->with($relationName);

            return;
        }

        $relationInstance = $query->getModel()->{$relationName}();

        // BUG FIX / HONESTY FIX: relationKeyColumns() below only knows the
        // correct key columns for HasOneOrMany, BelongsTo, and
        // BelongsToMany. Laravel also has HasManyThrough, HasOneThrough,
        // and polymorphic MorphTo — plausible in a School ERP (e.g.
        // students through enrollments to classes) — whose internal
        // column requirements for a *restricted* select() aren't ones this
        // was verified against. Guessing wrong here would silently
        // reintroduce the exact empty-relation bug fixed earlier, just for
        // a rarer relation type. Falling back to an unrestricted with()
        // for anything not explicitly known-safe trades the column-scoping
        // optimization for guaranteed correctness on those relation types.
        if (! $this->hasKnownKeyColumns($relationInstance)) {
            $query->with($relationName);

            return;
        }

        $keyColumns = $this->relationKeyColumns($relationInstance);

        // BUG FIX (confirmed by an actual test run, not just static
        // reading): with()'s closure form receives the Relation instance
        // itself (e.g. HasMany) here, not a Builder — unlike whereHas(),
        // which does pass a real Builder. Relation forwards where()/
        // select() to its underlying query via __call, so the calls below
        // work fine either way; the bug was purely the `Builder $q` type
        // hint rejecting the HasMany instance Eloquent actually passes.
        $query->with([$relationName => function (Relation $q) use ($constraint, $columns, $keyColumns): void {
            $q->where($constraint)->select(array_values(array_unique([...$keyColumns, ...$columns])));
        }]);
    }

    /** Whether relationKeyColumns() below has a verified answer for this relation type. */
    private function hasKnownKeyColumns(Relation $relation): bool
    {
        return $relation instanceof HasOneOrMany
            || $relation instanceof BelongsTo
            || $relation instanceof BelongsToMany;
    }

    /** Columns Eloquent needs present (beyond the ones the caller asked for) to hydrate this relation correctly. */
    private function relationKeyColumns(Relation $relation): array
    {
        $columns = [$relation->getRelated()->getKeyName()];

        if ($relation instanceof HasOneOrMany) {
            $columns[] = $relation->getForeignKeyName();
        } elseif ($relation instanceof BelongsTo) {
            $columns[] = $relation->getOwnerKeyName();
        } elseif ($relation instanceof BelongsToMany) {
            // Pivot keys are attached by Eloquent itself via the pivot
            // relation; nothing extra needs to be selected here.
        }

        return array_values(array_unique($columns));
    }

    private function applyFilter(Builder $query, array $filter): void
    {
        $field = $filter['field'];
        $value = $filter['value'];

        match ($filter['operator']) {
            'in' => $query->whereIn($field, $this->toArray($value)),
            'between' => $query->whereBetween($field, $this->toArray($value)),
            'like' => $query->where($field, 'like', "%{$value}%"),
            default => $query->where($field, $filter['operator'], $value),
        };
    }

    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : array_map('trim', explode(',', (string) $value));
    }
}
