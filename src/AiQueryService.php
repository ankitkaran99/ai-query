<?php

namespace Scholar\AiQuery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Scholar\AiQuery\Exceptions\InvalidQuerySpecException;
use Scholar\AiQuery\Exceptions\UnknownQueryableException;
use Scholar\AiQuery\Support\QueryBuilder;
use Scholar\AiQuery\Support\QueryableDefinition;
use Scholar\AiQuery\Support\QuerySpecValidator;
use Scholar\AiQuery\Support\SpecCache;
use Scholar\AiQuery\Support\SpecGenerator;
use Scholar\AiQuery\Support\TokenUsage;

final class AiQueryService
{
    public function __construct(
        private readonly QueryableRegistry $registry,
        private readonly SpecGenerator $generator,
        private readonly QuerySpecValidator $validator,
        private readonly QueryBuilder $builder,
        private readonly SpecCache $cache,
        private readonly array $config,
    ) {
    }

    /**
     * Register a model as askable. Call this once (e.g. in a service
     * provider's boot method) per model you want the AI to be able to query.
     * Prefer app/AiQuery/*Queryable classes (auto-discovered) where possible.
     */
    public function register(string $key, string $modelClass): QueryableDefinition
    {
        return $this->registry->register($key, $modelClass);
    }

    /**
     * Ask a natural-language question. Which registered queryable it's
     * about is decided by the model itself, from everything you've
     * registered — you don't say which one.
     *
     *   AiQuery::ask('Which students have not paid fees this month?');
     *   AiQuery::ask('How many students have not paid fees this month?');
     *
     * @param string $instructions Optional developer-supplied guidance folded into the
     *                             system prompt (e.g. "prefer the current term when no
     *                             date is given"). This is trusted, app-controlled text —
     *                             never pass raw end-user input here.
     */
    public function ask(string $prompt, ?string $instructions = null): AiQueryResult
    {
        if ($this->registry->isEmpty()) {
            throw new InvalidQuerySpecException('No queryables are registered — there is nothing to ask about.');
        }

        $fingerprint = $this->registry->fingerprint();

        $cachedSpec = $this->cache->get($prompt, $fingerprint, $instructions);
        $fromCache = $cachedSpec !== null;

        if ($fromCache) {
            $spec = $cachedSpec;
            $specUsage = TokenUsage::none(); // no LLM call happened for the spec this time
        } else {
            $generated = $this->generator->generate($prompt, $instructions);
            $spec = $generated->spec;
            $specUsage = $generated->usage;
            $this->cache->put($prompt, $fingerprint, $spec, $instructions);
        }

        // The model chose its own target (see SpecGenerator) — resolve it
        // here rather than trusting it blindly. An unregistered/hallucinated
        // target surfaces as the same InvalidQuerySpecException as any
        // other malformed spec, not a different exception type the caller
        // has to know to catch separately.
        $definition = $this->resolveDefinition($spec);

        // Validation always runs, cache hit or not — a stricter rule
        // shipped later shouldn't be bypassable by a spec cached under
        // older rules.
        $this->validator->validate($spec, $definition);

        $query = $this->builder->build($spec, $definition);
        $aggregate = $spec['aggregate'] ?? 'list';

        if (in_array($aggregate, ['count', 'sum', 'avg'], true)) {
            return $this->runAggregate($prompt, $spec, $query, $aggregate, $fromCache, $specUsage);
        }

        $results = $query->limit($this->config['max_rows'])->get();

        [$summary, $summaryUsage] = $this->config['summarize']
            ? $this->summarizeList($prompt, $results)
            : [null, TokenUsage::none()];

        return new AiQueryResult(
            prompt: $prompt,
            spec: $spec,
            results: $results,
            summary: $summary,
            fromCachedSpec: $fromCache,
            tokenUsage: $specUsage->add($summaryUsage),
        );
    }

    private function resolveDefinition(array $spec): QueryableDefinition
    {
        $target = $spec['target'] ?? null;

        if (! is_string($target) || $target === '') {
            throw new InvalidQuerySpecException('The model did not choose a target to query.');
        }

        try {
            return $this->registry->get($target);
        } catch (UnknownQueryableException $e) {
            throw new InvalidQuerySpecException("The model chose an unknown target \"{$target}\".", previous: $e);
        }
    }

    private function runAggregate(string $prompt, array $spec, Builder $query, string $aggregate, bool $fromCache, TokenUsage $specUsage): AiQueryResult
    {
        // Eloquent's sum() coalesces "no matching rows" to 0 internally,
        // but avg() does not — AVG() over zero rows is NULL in SQL, and
        // that's kept nullable end to end rather than cast to 0.0.
        $value = match ($aggregate) {
            'count' => $query->count(),
            'sum' => (float) $query->sum($spec['aggregate_field']),
            'avg' => ($raw = $query->avg($spec['aggregate_field'])) !== null ? (float) $raw : null,
        };

        [$summary, $summaryUsage] = $this->config['summarize']
            ? $this->summarizeAggregate($prompt, $aggregate, $value)
            : [null, TokenUsage::none()];

        return new AiQueryResult(
            $prompt, $spec, collect(), $summary, $fromCache, $aggregate, $value,
            $specUsage->add($summaryUsage)
        );
    }

    /** @return array{0: ?string, 1: TokenUsage} */
    private function summarizeAggregate(string $prompt, string $aggregate, int|float|null $value): array
    {
        if ($value === null) {
            return ['No matching records were found to average.', TokenUsage::none()];
        }

        $response = Prism::text()
            ->using($this->config['provider'], $this->config['model'])
            ->withSystemPrompt('Summarize this single-number query result in one plain sentence. Do not invent additional data.')
            ->withPrompt("Question: {$prompt}\n" . strtoupper($aggregate) . ": {$value}")
            ->asText();

        return [trim($response->text), TokenUsage::fromPrismResponse($response)];
    }

    /** @return array{0: ?string, 1: TokenUsage} */
    private function summarizeList(string $prompt, Collection $results): array
    {
        if ($results->isEmpty()) {
            return ['No matching records were found.', TokenUsage::none()];
        }

        $response = Prism::text()
            ->using($this->config['provider'], $this->config['model'])
            ->withSystemPrompt(
                'Summarize the query result in one or two plain sentences. '
                . 'State the total count. Do not invent data beyond what is shown.'
            )
            ->withPrompt("Question: {$prompt}\nTotal rows: {$results->count()}\nSample: " . $results->take(20)->toJson())
            ->asText();

        return [trim($response->text), TokenUsage::fromPrismResponse($response)];
    }
}
