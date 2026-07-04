<?php

return [

    // Which Prism provider/model to use. Switching providers is a config
    // change only — nothing in the library or your registrations changes.
    'provider' => env('AI_QUERY_PROVIDER', 'anthropic'),
    'model' => env('AI_QUERY_MODEL', 'claude-sonnet-4-6'),

    // Hard cap applied to every query, regardless of what was asked.
    'max_rows' => env('AI_QUERY_MAX_ROWS', 500),

    // Repeated questions ("who hasn't paid this month") are hashed and
    // cached to a query spec, so identical prompts skip the LLM call
    // entirely. This is the main cost/latency optimization in the library.
    'cache' => [
        'enabled' => env('AI_QUERY_CACHE_ENABLED', true),
        'store' => env('AI_QUERY_CACHE_STORE'), // null = default cache store
        'ttl' => env('AI_QUERY_CACHE_TTL', 3600), // seconds
        // Deliberately more specific than "ai-query": the entire point of
        // tagging is to flush only this library's own cache entries, which
        // a generic tag name undermines if anything else in the app (or
        // another package) happens to tag its own cache with the same name.
        'tag' => env('AI_QUERY_CACHE_TAG', 'scholar-ai-query-cache'),
    ],

    // Ask the model for a one-line natural-language summary alongside the
    // raw results. Costs one extra (cheap) call. Set false to skip it.
    'summarize' => env('AI_QUERY_SUMMARIZE', true),

    // Auto-discover app/AiQuery/*.php classes extending Scholar\AiQuery\Queryable
    // and register them, so no manual AiQuery::register() calls are needed.
    'discovery' => [
        'enabled' => env('AI_QUERY_DISCOVERY_ENABLED', true),
        'path' => app_path('AiQuery'),
    ],

];
