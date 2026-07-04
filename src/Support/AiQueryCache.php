<?php

namespace Scholar\AiQuery\Support;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every cache entry AI Query writes (spec cache, resolved-column cache,
 * prompt-schema cache) goes through here, tagged together, so it can all
 * be cleared with one command without touching the rest of the
 * application's cache.
 *
 * Named AiQueryCache rather than "TaggedCache" deliberately: Laravel's own
 * Repository::tags() returns an instance of Illuminate\Cache\TaggedCache,
 * and reusing that exact short name in a different namespace — while not
 * a compile error — would be a genuinely confusing collision for anyone
 * grepping the codebase later.
 *
 * Tagging only works on stores that implement it (Redis, Memcached, the
 * array driver) — Laravel's file and database drivers don't. Rather than
 * assert the exact internal class hierarchy that distinguishes them
 * (unverifiable from here without a PHP interpreter, and Laravel's own
 * taggable/non-taggable split has shifted across versions), taggability
 * is detected by trying tags() and catching whatever it throws when
 * unsupported. Untagged stores still cache correctly — they just can't be
 * selectively flushed later; see ClearCacheCommand.
 *
 * The taggability check is memoized per store (see $taggable below):
 * without that, every single get()/put()/remember() call — which happens
 * on every AI query, not just at boot — would re-throw and re-catch an
 * exception on every non-taggable store, which is real per-request
 * overhead for something that never changes at runtime under normal
 * deployment. The one case where that assumption doesn't hold is a
 * long-lived worker process (Octane, RoadRunner) that reconfigures cache
 * connections dynamically mid-process; resetMemoizedTaggability() exists
 * for that scenario specifically.
 */
final class AiQueryCache
{
    private const DEFAULT_TAG = 'scholar-ai-query-cache';

    /**
     * Deliberately config-driven rather than a fixed constant: the entire
     * point of tagging is to flush only this library's own entries, which
     * a name collision with an unrelated tag elsewhere in the host app
     * (or another package) would silently defeat. Configurable in case
     * scholar-ai-query-cache itself ever collides with something.
     */
    public static function tag(): string
    {
        return config('ai-query.cache.tag', self::DEFAULT_TAG);
    }

    /** @var array<string, bool> */
    private static array $taggable = [];

    public static function remember(?string $store, string $key, DateInterval|DateTimeInterface|int $ttl, Closure $callback): mixed
    {
        return static::repository($store)->remember($key, $ttl, $callback);
    }

    public static function get(?string $store, string $key): mixed
    {
        return static::repository($store)->get($key);
    }

    public static function put(?string $store, string $key, mixed $value, DateInterval|DateTimeInterface|int $ttl): void
    {
        static::repository($store)->put($key, $value, $ttl);
    }

    public static function supportsTagging(?string $store): bool
    {
        $memoKey = $store ?? '__default__';

        return self::$taggable[$memoKey] ??= self::probeTagging($store);
    }

    /** @return bool Whether a tag-based flush actually happened. */
    public static function flush(?string $store): bool
    {
        if (! static::supportsTagging($store)) {
            return false;
        }

        Cache::store($store)->tags([self::tag()])->flush();

        return true;
    }

    /** For long-lived workers (Octane/RoadRunner) that reconfigure cache connections mid-process. */
    public static function resetMemoizedTaggability(): void
    {
        self::$taggable = [];
    }

    private static function repository(?string $store): Repository
    {
        $cache = Cache::store($store);

        return static::supportsTagging($store) ? $cache->tags([self::tag()]) : $cache;
    }

    private static function probeTagging(?string $store): bool
    {
        try {
            Cache::store($store)->tags([self::tag()]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
