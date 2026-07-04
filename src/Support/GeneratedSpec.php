<?php

namespace Scholar\AiQuery\Support;

/** The result of one SpecGenerator::generate() call: the spec itself, and what it cost in tokens. */
final class GeneratedSpec
{
    public function __construct(
        public readonly array $spec,
        public readonly TokenUsage $usage,
    ) {
    }
}
