<?php

namespace Scholar\AiQuery\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use ReflectionClass;
use Scholar\AiQuery\Queryable;

/**
 * Describes one "askable" model: which columns and relations the AI is
 * allowed to reference, and an optional scope that is ALWAYS applied last
 * when building the query — so it can never be overridden by anything the
 * LLM returns (use it for tenant scoping, soft-delete rules, etc.).
 *
 * Can be built two ways:
 *  - fluently:  new QueryableDefinition(...)->columns([...])->relation(...)
 *  - declaratively: QueryableDefinition::fromQueryable($queryableInstance)
 */
final class QueryableDefinition
{
    /** @var array<string, array<int, string>> relation name => allowed columns */
    private array $relations = [];

    private ?Closure $scope = null;

    private string $description = '';

    public function __construct(
        public readonly string $key,
        public readonly string $modelClass,
        private array $columns = [],
    ) {
    }

    /** Build a definition from an app/AiQuery/*Queryable class. */
    public static function fromQueryable(Queryable $queryable): self
    {
        $definition = new self($queryable->key(), $queryable->model());
        $definition->description($queryable->description());

        $columns = $queryable->columns()
            ?? $definition->resolveModelColumns(new ($queryable->model())());
        $definition->columns($columns);

        foreach ($queryable->relations() as $name => $relationColumns) {
            $definition->relation(
                $name,
                $relationColumns ?? $definition->resolveRelationColumns($name)
            );
        }

        $definition->scope(fn (Builder $query) => $queryable->scope($query));

        return $definition;
    }

    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /** Auto-resolve columns from the model's $fillable (fallback: $visible). */
    public function autoColumns(?array $except = null): static
    {
        $columns = $this->resolveModelColumns(new ($this->modelClass)());

        if ($except) {
            $columns = array_values(array_diff($columns, $except));
        }

        return $this->columns($columns);
    }

    public function relation(string $name, array $columns): static
    {
        $this->relations[$name] = $columns;

        return $this;
    }

    /** Expose a relation, auto-resolving its columns from $fillable if not given. */
    public function exposeRelation(string $name, ?array $columns = null): static
    {
        return $this->relation($name, $columns ?? $this->resolveRelationColumns($name));
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function scope(Closure $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function allowedColumns(): array
    {
        return $this->columns;
    }

    public function allowedRelations(): array
    {
        return $this->relations;
    }

    public function relationColumns(string $relation): ?array
    {
        return $this->relations[$relation] ?? null;
    }

    public function getScope(): ?Closure
    {
        return $this->scope;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** Human-readable block folded into the LLM system prompt. */
    public function toPromptSchema(): string
    {
        $lines = ['- "' . $this->key . '" (' . $this->modelClass . ')' . ($this->description !== '' ? ': ' . $this->description : '')];
        $lines[] = '  columns: ' . implode(', ', $this->columns);

        foreach ($this->relations as $name => $cols) {
            $lines[] = '  relation "' . $name . '" columns: ' . implode(', ', $cols);
        }

        return implode("\n", $lines);
    }

    private function resolveModelColumns(Model $model): array
    {
        // OPTIMIZATION: autoColumns()/exposeRelation()/class discovery all
        // run inside AiQueryServiceProvider::boot(), which fires on every
        // request whether or not AiQuery::ask() is ever called that
        // request. Without caching, that's a fresh model instantiation +
        // getFillable() call per queryable, per request, for zero benefit
        // on requests that never touch the AI feature. Cache the resolved
        // array instead.
        //
        // BUG FIX: a flat 24h TTL meant that removing a column from
        // $fillable specifically to hide it from the AI (a real security
        // action, not just a refactor) could still serve the old, wider
        // column list for up to a day post-deploy. The cache key now
        // includes the model file's mtime, so it busts automatically the
        // moment the file changes — on deploy in production, and on save
        // during local development.
        return AiQueryCache::remember(
            config('ai-query.cache.store'),
            'ai-query:model-columns:' . get_class($model) . ':' . $this->fileVersion(get_class($model)),
            now()->addDay(),
            function () use ($model): array {
                $columns = $model->getFillable();

                if ($columns !== []) {
                    return $columns;
                }

                $visible = $model->getVisible();

                if ($visible !== []) {
                    return $visible;
                }

                throw new InvalidArgumentException(
                    get_class($model) . ' has no $fillable or $visible defined — pass columns explicitly instead of auto-resolving.'
                );
            }
        );
    }

    private function resolveRelationColumns(string $name): array
    {
        // BUG FIX: this used to wrap the whole method in its own
        // Cache::remember, keyed only by the *parent* model's file
        // version. But the value being cached is the *related* model's
        // columns — if FeePayment's $fillable changed (including removing
        // a column specifically to stop exposing it to the AI, which is
        // exactly the scenario the file-mtime busting elsewhere is meant
        // to protect) while Student.php itself didn't change, this key
        // never busted and kept serving the stale, wider column list.
        // Relation instantiation itself is cheap (no I/O — Eloquent
        // relations are lazy), so there's nothing worth caching here that
        // resolveModelColumns() below doesn't already cache correctly,
        // keyed by the actual related class.
        $instance = new ($this->modelClass)();

        if (! method_exists($instance, $name)) {
            throw new InvalidArgumentException("Relation \"{$name}\" does not exist on {$this->modelClass}.");
        }

        $relation = $instance->{$name}();

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException("\"{$name}\" is not an Eloquent relation on {$this->modelClass}.");
        }

        return $this->resolveModelColumns($relation->getRelated());
    }

    /** File modification time of a class, used to auto-bust caches on deploy/edit. */
    private function fileVersion(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $mtime = $file ? @filemtime($file) : false;

        return $mtime !== false ? (string) $mtime : '0';
    }
}
