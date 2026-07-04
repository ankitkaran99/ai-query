<?php

namespace Scholar\AiQuery\Facades;

use Illuminate\Support\Facades\Facade;
use Scholar\AiQuery\AiQueryResult;
use Scholar\AiQuery\Support\QueryableDefinition;

/**
 * @method static QueryableDefinition register(string $key, string $modelClass)
 * @method static AiQueryResult ask(string $prompt, ?string $instructions = null)
 */
final class AiQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Scholar\AiQuery\AiQueryService::class;
    }
}
