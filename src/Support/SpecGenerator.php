<?php

namespace Scholar\AiQuery\Support;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Scholar\AiQuery\QueryableRegistry;

/**
 * Asks the LLM for a structured query spec (JSON matching a schema) rather
 * than for code. The model never sees anything it could turn into an
 * executable statement — only field names, operators, and values.
 *
 * The model chooses which registered queryable to target itself (the
 * schema's "target" enum lists every registered key) — callers don't say
 * which one up front. That's a deliberate tradeoff: it means one fewer
 * argument to pass, but a wrong-target mistake (routing a students
 * question against "teachers" because the wording overlapped) is now
 * possible in a way it wasn't before, and QuerySpecValidator can't catch
 * it — a wrong-but-structurally-valid target still validates fine. Keep
 * queryable descriptions specific if you register several similar ones.
 */
final class SpecGenerator
{
    public function __construct(
        private readonly QueryableRegistry $registry,
        private readonly array $config,
    ) {
    }

    /**
     * @param string $prompt The end user's natural-language question.
     * @param string|null $instructions Developer-supplied guidance folded into the system prompt
     *                                  (e.g. "prefer the current month when no date is given").
     *                                  This is trusted, app-controlled text — never pass raw
     *                                  end-user input here, or you reopen the prompt-injection
     *                                  surface this design otherwise avoids.
     */
    public function generate(string $prompt, ?string $instructions = null): GeneratedSpec
    {
        $response = Prism::structured()
            ->using($this->config['provider'], $this->config['model'])
            ->withSystemPrompt($this->systemPrompt($instructions))
            ->withSchema($this->schema())
            ->withPrompt($prompt)
            ->asStructured();

        return new GeneratedSpec($response->structured, TokenUsage::fromPrismResponse($response));
    }

    private function systemPrompt(?string $instructions): string
    {
        $extra = $instructions !== null && trim($instructions) !== ''
            ? "\n\nAdditional instructions from the application (trusted, not from the end user):\n{$instructions}\n"
            : '';

        return <<<PROMPT
        You translate a natural-language question into a structured, read-only
        query specification. You never write code, SQL, or explanations — only
        the JSON fields defined by the schema you were given.

        Choose the "target" that best matches what the question is about, from
        the following registered data — and only reference fields/relations
        listed under whichever target you choose:

        {$this->registry->promptSchema()}
        {$extra}
        Rules:
        - Never invent a field or relation, or reference one from a target
          other than the one you chose, even if it seems obviously related.
        - Prefer the smallest set of filters that answers the question.
        - Use "relation_filters" for conditions that live on a related model
          (e.g. a payment status, an attendance percentage). If more than one
          condition applies to the *same* relation, put them together in one
          relation_filter entry's "filters" array — don't create two separate
          relation_filter entries for the same relation.
        - Set "aggregate" to "count" only when the question asks for a number
          or total of rows; "sum"/"avg" when it asks for a total or average of
          a specific numeric field (set "aggregate_field" to that field in
          that case); otherwise use "list".
        PROMPT;
    }

    private function schema(): ObjectSchema
    {
        $filterSchema = new ObjectSchema('filter', 'A single filter condition', [
            new StringSchema('field', 'Column name'),
            new EnumSchema('operator', 'Comparison operator', ['=', '!=', '<', '>', '<=', '>=', 'in', 'between', 'like']),
            new StringSchema('value', 'Value to compare against (comma-separated if multiple)'),
        ], requiredFields: ['field', 'operator', 'value']);

        $relationFilterSchema = new ObjectSchema('relation_filter', 'Filters applied through a relation', [
            new StringSchema('relation', 'Relation name'),
            new ArraySchema('filters', 'Filters on the related model', $filterSchema),
        ], requiredFields: ['relation', 'filters']);

        return new ObjectSchema('query_spec', 'A structured, read-only report query', [
            new EnumSchema('target', 'Which registered queryable this question is about', $this->registry->keys()),
            new ArraySchema('filters', 'Direct filters on the target', $filterSchema),
            new ArraySchema('relation_filters', 'Filters applied via relations', $relationFilterSchema),
            new EnumSchema('aggregate', 'How to summarize the result', ['count', 'list', 'sum', 'avg']),
            new StringSchema('aggregate_field', 'Column to sum/average — required only when aggregate is "sum" or "avg"'),
        ], requiredFields: ['target', 'filters']);
    }
}
