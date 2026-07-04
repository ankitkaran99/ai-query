<?php

namespace Scholar\AiQuery;

use Scholar\AiQuery\Exceptions\UnknownQueryableException;
use Scholar\AiQuery\Support\AiQueryCache;
use Scholar\AiQuery\Support\QueryableDefinition;

final class QueryableRegistry
{
    /** @var array<string, QueryableDefinition> */
    private array $definitions = [];

    public function register(string $key, string $modelClass): QueryableDefinition
    {
        return $this->add(new QueryableDefinition($key, $modelClass));
    }

    /** Register a fully-built definition, e.g. from QueryableDefinition::fromQueryable(). */
    public function add(QueryableDefinition $definition): QueryableDefinition
    {
        return $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): QueryableDefinition
    {
        return $this->definitions[$key]
            ?? throw new UnknownQueryableException(
                "\"{$key}\" is not registered. Call AiQuery::register('{$key}', Model::class) before asking about it."
            );
    }

    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    public function isEmpty(): bool
    {
        return $this->definitions === [];
    }

    /**
     * Cached, human-readable description of everything the AI may query.
     * Rebuilt only when the registry's shape actually changes, so this
     * string isn't re-rendered on every single request.
     */
    public function promptSchema(): string
    {
        return AiQueryCache::remember(
            config('ai-query.cache.store'),
            'ai-query:schema:' . $this->fingerprint(),
            now()->addDay(),
            fn () => implode("\n\n", array_map(
                static fn (QueryableDefinition $d) => $d->toPromptSchema(),
                $this->definitions
            ))
        );
    }

    /** Stable hash of the current registry shape; doubles as a cache key. */
    public function fingerprint(): string
    {
        return substr(sha1(serialize(array_map(
            static fn (QueryableDefinition $d) => [$d->key, $d->allowedColumns(), $d->allowedRelations()],
            $this->definitions
        ))), 0, 12);
    }
}
