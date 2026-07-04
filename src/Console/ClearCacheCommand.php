<?php

namespace Scholar\AiQuery\Console;

use Illuminate\Console\Command;
use Scholar\AiQuery\Support\AiQueryCache;

class ClearCacheCommand extends Command
{
    protected $signature = 'ai-query:clear-cache {--store= : Override the cache store to clear (defaults to ai-query.cache.store / your default store)}';

    protected $description = 'Clear cached AI Query spec/schema/column data';

    public function handle(): int
    {
        $store = $this->option('store') ?: config('ai-query.cache.store');

        if (AiQueryCache::flush($store)) {
            $this->info('Cleared AI Query\'s cache' . ($store ? " (store: {$store})." : ' (default store).'));

            return self::SUCCESS;
        }

        $this->warn(
            'The configured cache store does not support tag-based flushing '
            . '(this is normal for the "file" and "database" drivers — Laravel only '
            . 'supports it on Redis, Memcached, and the array driver).'
        );

        $this->line('');
        $this->line('AI Query\'s cache entries still expire correctly on their own TTL either way —');
        $this->line('this command only affects whether the *space* they used gets reclaimed early.');
        $this->line('Options:');
        $this->line('  - Run `php artisan cache:clear` to clear the entire store (affects other cached app data too).');
        $this->line('  - Set AI_QUERY_CACHE_STORE to a taggable store (redis/memcached/array) if selective clearing matters to you.');

        return self::FAILURE;
    }
}
