<?php

namespace Scholar\AiQuery\Exceptions;

use RuntimeException;

/**
 * Thrown whenever the LLM's structured output references a field, relation,
 * or operator that isn't on the allow-list. This is the safety boundary:
 * it fires on hallucinated or malformed fields before any query runs.
 */
final class InvalidQuerySpecException extends RuntimeException
{
}
