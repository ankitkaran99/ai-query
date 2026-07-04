<?php

namespace Scholar\AiQuery\Support;

/**
 * Token usage for one AI Query call, summed across every underlying Prism
 * call that went into it (the spec-generation call, plus the optional
 * summary call — a served-from-cache spec contributes zero, since no LLM
 * call happened for it that time).
 *
 * Field names (promptTokens/completionTokens) match Prism's own response
 * object exactly, confirmed against Prism's documentation examples
 * (`$response->usage->promptTokens`, `$response->usage->completionTokens`)
 * rather than assumed from memory.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function add(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
        );
    }

    public static function none(): self
    {
        return new self();
    }

    /** Builds usage from a Prism response, tolerating a missing/differently-shaped usage object rather than fataling. */
    public static function fromPrismResponse(mixed $response): self
    {
        $usage = $response->usage ?? null;

        if ($usage === null) {
            return self::none();
        }

        return new self(
            promptTokens: (int) ($usage->promptTokens ?? 0),
            completionTokens: (int) ($usage->completionTokens ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens(),
        ];
    }
}
